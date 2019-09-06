<?php


namespace App\Listener;

use Doctrine\Common\Collections\ArrayCollection;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Chat implements MessageComponentInterface {

    protected $clients;
    private $clientInfo;
    private $holdingClients;
    private $wannaTalk;
    private $connectedUsers;

    public function __construct() {
        $this->clientInfo = new ArrayCollection();
        $this->wannaTalk = new ArrayCollection();
        $this->holdingClients = new ArrayCollection();
        $this->connectedUsers = new ArrayCollection();
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $this->holdingClients->set($conn->resourceId,$conn);
        $conn->send(json_encode(['type' => 'info', 'msg' => 'Your id - '.$conn->resourceId]));

        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {

        $msg = json_decode($msg);

        switch ($msg[0]){
            case 'find-talk':
                $this->wannaTalk->set($from->resourceId,$from);
                $this->holdingClients->remove($from->resourceId);
                $this->clientInfo->set($from->resourceId, $msg[1]);
                if(sizeof($this->clients) == 1){
                    $from->send(json_encode(['type' => 'info', 'msg' => 'Сейчас в чате находитесь только вы']));
                }
                elseif(sizeof($this->wannaTalk) <= 1){
                        $from->send(json_encode(['type' => 'wait']));
                }
                elseif(sizeof($this->wannaTalk) > 1){
                    $talker = $this->wannaTalk->get(array_rand($this->wannaTalk->toArray()));
                    while ($talker == $from)
                        $talker = $this->wannaTalk->get(array_rand($this->wannaTalk->toArray()));
                    $from->send(json_encode(['type' => 'connected', 'msg' => 'new talker with id '.$talker->resourceId, 'name' => $this->clientInfo->get($talker->resourceId)]));
                    $talker->send(json_encode(['type' => 'connected', 'msg' => 'new talker with id '.$from->resourceId, 'name' => $this->clientInfo->get($from->resourceId)]));
                    $this->wannaTalk->remove($from->resourceId);
                    $this->wannaTalk->remove($talker->resourceId);
                    $this->connectedUsers->add([$from, $talker]);
                }
            break;
            case 'send-msg':
                $arr = $this->connectedUsers->toArray();
                foreach ($arr as $item) {
                    if($from == $item[0]){
                        $item[1]->send(json_encode(['type' => 'msg', 'msg' =>$msg[1]]));
                    }
                    elseif($from == $item[1]){
                        $item[0]->send(json_encode(['type' => 'msg', 'msg' => $msg[1]]));
                    }
                }
                break;
            case 'next-talker':
                $arr = $this->connectedUsers->toArray();
                foreach ($arr as $key => $item) {
                    if($from == $item[0] || $from == $item[1]){
                        $this->wannaTalk->set($item[1]->resourceId,$item[1]);
                        $this->wannaTalk->set($item[0]->resourceId,$item[0]);
                        $item[0]->send(json_encode(['type' => 'closed-talk']));
                        $item[1]->send(json_encode(['type' => 'closed-talk']));
                        $this->connectedUsers->remove($key);
                        if(sizeof($this->clients) == 1){
                            $from->send(json_encode(['type' => 'info', 'msg' => 'Сейчас в чате находитесь только вы']));
                        }
                        elseif(sizeof($this->wannaTalk) <= 1){
                            $from->send(json_encode(['type' => 'wait']));
                        }
                        elseif(sizeof($this->wannaTalk) > 1){
                            $talker = $this->wannaTalk->get(array_rand($this->wannaTalk->toArray()));
                            while ($talker == $from)
                                $talker = $this->wannaTalk->get(array_rand($this->wannaTalk->toArray()));
                            $from->send(json_encode(['type' => 'connected', 'msg' => 'new talker with id '.$talker->resourceId, 'name' => $this->clientInfo->get($talker->resourceId)]));
                            $talker->send(json_encode(['type' => 'connected', 'msg' => 'new talker with id '.$from->resourceId, 'name' => $this->clientInfo->get($talker->resourceId)]));
                            $this->wannaTalk->remove($from->resourceId);
                            $this->wannaTalk->remove($talker->resourceId);
                            $this->connectedUsers->add([$from, $talker]);
                        }
                    }
                }
                break;
            case 'stop':
                $this->wannaTalk->remove($from->resourceId);
                $this->holdingClients->set($from->resourceId, $from);
                $arr = $this->connectedUsers->toArray();
                foreach ($arr as $key => $item) {
                    if($from == $item[0]){
                        $this->wannaTalk->set($item[1]->resourceId,$item[1]);
                        $item[1]->send(json_encode(['type' => 'closed-talk']));
                        $this->connectedUsers->remove($key);
                    }
                    elseif($from == $item[1]){
                        $this->wannaTalk->set($item[0]->resourceId,$item[0]);
                        $item[0]->send(json_encode(['type' => 'closed-talk']));
                        $this->connectedUsers->remove($key);
                    }
                }
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        $this->wannaTalk->remove($conn->resourceId);
        $this->holdingClients->remove($conn->resourceId);
        $arr = $this->connectedUsers->toArray();
        foreach ($arr as $key => $item) {
            if($conn == $item[0]){
                $this->wannaTalk->set($item[1]->resourceId,$item[1]);
                $item[1]->send(json_encode(['type' => 'closed-talk']));
                $this->connectedUsers->remove($key);
            }
            elseif($conn == $item[1]){
                $this->wannaTalk->set($item[0]->resourceId,$item[0]);
                $item[0]->send(json_encode(['type' => 'closed-talk']));
                $this->connectedUsers->remove($key);
            }
        }


        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";

        $conn->close();
    }
}