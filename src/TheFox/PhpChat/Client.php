<?php

namespace TheFox\PhpChat;

use Exception;
use RuntimeException;

use Rhumsaa\Uuid\Uuid;
use Rhumsaa\Uuid\Exception\UnsatisfiedDependencyException;

use TheFox\Utilities\Hex;
use TheFox\Network\AbstractSocket;
use TheFox\Dht\Kademlia\Node;

class Client{
	
	const MSG_SEPARATOR = "\n";
	
	private $id = 0;
	private $status = array();
	
	private $server = null;
	private $socket = null;
	private $node = null;
	private $ip = '';
	private $port = 0;
	
	private $recvBufferTmp = '';
	
	public function __construct(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
		
		$this->status['hasShutdown'] = false;
		$this->status['isChannel'] = false;
		
		$this->status['hasId'] = false;
	}
	
	public function __destruct(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
	}
	
	public function setId($id){
		$this->id = $id;
	}
	
	public function getId(){
		return $this->id;
	}
	
	public function getStatus($name){
		if(array_key_exists($name, $this->status)){
			return $this->status[$name];
		}
		return null;
	}
	
	public function setStatus($name, $value){
		$this->status[$name] = $value;
	}
	
	public function setServer(Server $server){
		$this->server = $server;
	}
	
	public function getServer(){
		return $this->server;
	}
	
	public function setSocket(AbstractSocket $socket){
		$this->socket = $socket;
	}
	
	public function getSocket(){
		return $this->socket;
	}
	
	public function setNode(Node $node){
		$this->node = $node;
	}
	
	public function getNode(){
		return $this->node;
	}
	
	public function setIp($ip){
		$this->ip = $ip;
	}
	
	public function getIp(){
		if(!$this->ip){
			$this->setIpPort();
		}
		return $this->ip;
	}
	
	public function setPort($port){
		$this->port = $port;
	}
	
	public function getPort(){
		if(!$this->port){
			$this->setIpPort();
		}
		return $this->port;
	}
	
	public function setIpPort($ip = '', $port = 0){
		$this->getSocket()->getPeerName($ip, $port);
		$this->setIp($ip);
		$this->setPort($port);
	}
	
	public function getIpPort(){
		return $this->getIp().':'.$this->getPort();
	}
	
	public function setSslPrv($sslKeyPrvPath, $sslKeyPrvPass){
		$this->ssl = openssl_pkey_get_private(file_get_contents($sslKeyPrvPath), $sslKeyPrvPass);
	}
	
	public function getLocalNode(){
		if($this->getServer()){
			return $this->getServer()->getLocalNode();
		}
		return null;
	}
	
	public function getSettings(){
		if($this->getServer()){
			return $this->getServer()->getSettings();
		}
		
		return null;
	}
	
	private function getLog(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
		
		if($this->getServer()){
			return $this->getServer()->getLog();
		}
		
		return null;
	}
	
	private function log($level, $msg){
		#print __CLASS__.'->'.__FUNCTION__.': '.$level.', '.$msg."\n";
		
		if($this->getLog()){
			if(method_exists($this->getLog(), $level)){
				$this->getLog()->$level($msg);
			}
		}
	}
	
	private function getTable(){
		if($this->getServer()){
			return $this->getServer()->getTable();
		}
		
		return null;
	}
	
	public function dataRecv(){
		$data = $this->getSocket()->read();
		
		$separatorPos = strpos($data, static::MSG_SEPARATOR);
		if($separatorPos === false){
			$this->recvBufferTmp .= $data;
			$data = '';
		}
		else{
			$msg = $this->recvBufferTmp.substr($data, 0, $separatorPos);
			
			$this->msgHandle($msg);
		}
	}
	
	private function msgHandle($msgRaw){
		$msgRaw = base64_decode($msgRaw);
		$msg = json_decode($msgRaw, true);
		
		$msgName = $msg['name'];
		$msgData = array();
		if(array_key_exists('data', $msg)){
			$msgData = $msg['data'];
		}
		
		print __CLASS__.'->'.__FUNCTION__.': '.$msgRaw."\n";
		#print __CLASS__.'->'.__FUNCTION__.': '.$msgName."\n";
		
		if($msgName == 'nop'){}
		elseif($msgName == 'hello'){
			if(array_key_exists('ip', $msgData)){
				$ip = $msgData['ip'];
				if($ip != '127.0.0.1' && strIsIp($ip) && $this->getSettings()){
					$this->getSettings()->data['node']['ipPub'] = $ip;
					$this->getSettings()->setDataChanged(true);
				}
			}
		}
		elseif($msgName == 'id'){
			if(!$this->getStatus('hasId')){
				$id = '';
				$port = 0;
				$sslKeyPub = null;
				$isChannel = false;
				if(array_key_exists('id', $msgData)){
					$id = $msgData['id'];
				}
				if(array_key_exists('port', $msgData)){
					$port = (int)$msgData['port'];
				}
				if(array_key_exists('sslKeyPub', $msgData)){
					$sslKeyPub = base64_decode($msgData['sslKeyPub']);
				}
				if(array_key_exists('isChannel', $msgData)){
					$isChannel = (bool)$msgData['isChannel'];
				}
				
				$node = new Node();
				$node->setIdHexStr($id);
				$node->setIp($this->getIp());
				$node->setPort($port);
				$node->setSslKeyPub($sslKeyPub);
				$node->setTimeLastSeen(time());
				
				$this->setStatus('isChannel', $this->getStatus('isChannel') | $isChannel);
				
				if(! $this->getLocalNode()->isEqual($node)){
					$this->setNode($node);
				}
				else{
					$this->sendError(120, $msgName);
				}
			}
			else{
				$this->sendError(110, $msgName);
			}
		}
		elseif($msgName == 'ping'){
			$id = '';
			if(array_key_exists('id', $msgData)){
				$id = $msgData['id'];
			}
			$this->sendPong($id);
		}
		elseif($msgName == 'error'){
			$code = 0;
			$msg = '';
			$name = '';
			if(array_key_exists('msg', $msgData)){
				$code = (int)$msgData['code'];
			}
			if(array_key_exists('msg', $msgData)){
				$msg = $msgData['msg'];
			}
			if(array_key_exists('msg', $msgData)){
				$name = $msgData['name'];
			}
			
			$this->log('debug', $this->getIp().':'.$this->getPort().' recv '.$msgName.': '.$code.', '.$msg.', '.$name);
		}
		elseif($msgName == 'quit'){
			$this->shutdown();
		}
	}
	
	private function msgCreate($name, $data){
		$json = array(
			'name' => $name,
			'data' => $data,
		);
		return json_encode($json);
	}
	
	private function dataSend($msg){
		$this->getSocket()->write($msg.static::MSG_SEPARATOR);
	}
	
	public function sendHello(){
		$data = array(
			'ip' => $this->getIp(),
		);
		$this->dataSend($this->msgCreate('hello', $data));
	}
	
	private function sendId($isChannel = false){
		if(!$this->getLocalNode()){
			throw new RuntimeException('localNode not set.');
		}
		
		$sslKeyPub = base64_encode($this->getLocalNode()->getSslKeyPub());
		
		$data = array(
			'id'        => $this->getLocalNode()->getIdHexStr(),
			'port'      => $this->getLocalNode()->getPort(),
			'sslKeyPub' => $sslKeyPub,
			'isChannel' => (bool)$isChannel,
		);
		$this->dataSend($this->msgCreate('id', $data));
	}
	
	private function sendPing($id = ''){
		$data = array(
			'id' => $id,
		);
		$this->dataSend($this->msgCreate('ping', $data));
	}
	
	private function sendPong($id = ''){
		$data = array(
			'id' => $id,
		);
		$this->dataSend($this->msgCreate('pong', $data));
	}
	
	private function sendError($errorCode = 999, $msgName = ''){
		$errors = array(
			// 100-199
			100 => 'You need to identify',
			110 => 'You already identified',
			120 => 'You are using my ID',
			
			999 => 'Unknown error',
		);
		
		if(!isset($errors[$errorCode])){
			throw new RuntimeException('Error '.$errorCode.' not defined.');
		}
		
		$data = array(
			'code'   => $errorCode,
			'msg' => $errors[$errorCode],
			'name' => $msgName,
		);
		$this->dataSend($this->msgCreate('error', $data));
	}
	
	public function shutdown(){
		if(!$this->getStatus('hasShutdown')){
			$this->setStatus('hasShutdown', true);
			
			$this->getSocket()->shutdown();
			$this->getSocket()->close();
			
			if($this->ssl){
				openssl_free_key($this->ssl);
			}
		}
	}
	
}
