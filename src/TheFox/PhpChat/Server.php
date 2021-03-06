<?php

namespace TheFox\PhpChat;

use Exception;

use TheFox\Logger\Logger;
use TheFox\Logger\StreamHandler;
use TheFox\Network\Socket;
use TheFox\Dht\Kademlia\Node;

class Server{
	
	private $log = null;
	
	private $kernel = null;
	private $ip = '';
	private $port = 0;
	
	private $clientsId = 0;
	private $clients = array();
	
	private $sslKeyPrvPath = null;
	private $sslKeyPrvPass = null;
	private $isListening = false;
	private $socket = null;
	private $hasDhtNetworkBootstrapped = false;
	
	public function __construct(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
		
		$this->log = new Logger('server');
		$this->log->pushHandler(new StreamHandler('php://stdout', Logger::ERROR));
		$this->log->pushHandler(new StreamHandler('log/server.log', Logger::DEBUG));
		
		$this->log->info('start');
	}
	
	public function getLog(){
		return $this->log;
	}
	
	public function setKernel($kernel){
		$this->kernel = $kernel;
	}
	
	public function getKernel(){
		return $this->kernel;
	}
	
	public function kernelHasConsole(){
		if($this->getKernel() && $this->getKernel()->getIpcConsoleConnection()
			&& $this->getKernel()->getIpcConsoleConnection()->getHandler()){
			return $this->getKernel()->getIpcConsoleConnection()->getHandler()->getClientsNum() > 0;
		}
		return false;
	}
	
	public function setIp($ip){
		$this->ip = $ip;
	}
	
	public function setPort($port){
		$this->port = $port;
	}
	
	public function setSslPrv($sslKeyPrvPath, $sslKeyPrvPass){
		$this->sslKeyPrvPath = $sslKeyPrvPath;
		$this->sslKeyPrvPass = $sslKeyPrvPass;
	}
	
	private function setHasDhtNetworkBootstrapped($hasDhtNetworkBootstrapped){
		$this->hasDhtNetworkBootstrapped = $hasDhtNetworkBootstrapped;
	}
	
	private function getHasDhtNetworkBootstrapped(){
		return $this->hasDhtNetworkBootstrapped;
	}
	
	public function getSettings(){
		if($this->getKernel()){
			return $this->getKernel()->getSettings();
		}
		return null;
	}
	
	public function getLocalNode(){
		if($this->getKernel()){
			return $this->getKernel()->getLocalNode();
		}
		return null;
	}
	
	public function getTable(){
		if($this->getKernel()){
			return $this->getKernel()->getTable();
		}
		
		return null;
	}
	
	public function getMsgDb(){
		if($this->getKernel()){
			return $this->getKernel()->getMsgDb();
		}
		
		return null;
	}
	
	public function init(){
		if($this->ip && $this->port){
			$this->log->notice('listen on '.$this->ip.':'.$this->port);
			
			$this->socket = new Socket();
			
			$bind = false;
			try{
				$bind = $this->socket->bind($this->ip, $this->port);
			}
			catch(Exception $e){
				$this->log->error($e->getMessage());
			}
			
			if($bind){
				try{
					if($this->socket->listen()){
						$this->log->notice('listen ok');
						$this->isListening = true;
						
						return true;
					}
				}
				catch(Exception $e){
					$this->log->error($e->getMessage());
				}
			}
			
		}
		
		$this->log->notice('listen failed');
		return false;
	}
	
	public function run(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
		#print __CLASS__.'->'.__FUNCTION__.': client '.count($this->clients)."\n";
		
		$readHandles = array();
		$writeHandles = null;
		$exceptHandles = null;
		
		if($this->isListening){
			$readHandles[] = $this->socket->getHandle();
		}
		foreach($this->clients as $clientId => $client){
			// Collect client handles.
			$readHandles[] = $client->getSocket()->getHandle();
			
			// Run client.
			#print __CLASS__.'->'.__FUNCTION__.': client run'."\n";
			$client->run();
		}
		$readHandlesNum = count($readHandles);
		
		$handlesChanged = $this->socket->select($readHandles, $writeHandles, $exceptHandles);
		#$this->log->debug('collect readable sockets: '.(int)$handlesChanged.'/'.$readHandlesNum);
		
		if($handlesChanged){
			foreach($readHandles as $readableHandle){
				if($this->isListening && $readableHandle == $this->socket->getHandle()){
					// Server
					$socket = $this->socket->accept();
					if($socket){
						$client = $this->clientNew($socket);
						
						$client->sendHello();
						
						$this->log->debug('new client: '.$client->getId().', '.$client->getIpPort());
					}
				}
				else{
					// Client
					$client = $this->clientGetByHandle($readableHandle);
					if($client){
						if(feof($client->getSocket()->getHandle())){
							$this->clientRemove($client);
						}
						else{
							#$this->log->debug('old client: '.$client->getId().', '.$client->getIpPort());
							$client->dataRecv();
							
							if($client->getStatus('hasShutdown')){
								$this->clientRemove($client);
							}
						}
					}
				}
			}
		}
	}
	
	public function shutdown(){
		$this->log->info('shutdown');
		
		$this->socket->close();
	}
	
	private function clientNew($socket){
		$this->clientsId++;
		#print __CLASS__.'->'.__FUNCTION__.': '.$this->clientsId."\n";
		
		$client = new Client();
		$client->setSocket($socket);
		$client->setSslPrv($this->sslKeyPrvPath, $this->sslKeyPrvPass);
		
		$client->setId($this->clientsId);
		$client->setServer($this);
		
		if($this->getSettings()->data['firstRun'] && !$this->getHasDhtNetworkBootstrapped()){
			$this->setHasDhtNetworkBootstrapped(true);
			
			$this->log->debug('dht network bootstrap');
			
			$action = new ClientAction(ClientAction::CRITERION_AFTER_ID_SUCCESSFULL);
			$action->functionSet(function($action, $client){
				$client->sendNodeFind($client->getLocalNode()->getIdHexStr());
			});
			$client->actionAdd($action);
		}
		
		$this->clients[$this->clientsId] = $client;
		#print __CLASS__.'->'.__FUNCTION__.': '.count($this->clients)."\n";
		
		return $client;
	}
	
	private function clientGetByHandle($handle){
		foreach($this->clients as $clientId => $client){
			if($client->getSocket()->getHandle() == $handle){
				return $client;
			}
		}
		
		return null;
	}
	
	public function clientTalkResponseSend(Client $client, $rid, $status, $userNickname = ''){
		if(isset($this->clients[$client->getId()])){
			$client = $this->clients[$client->getId()];
			$client->sendTalkResponse($rid, $status, $userNickname);
		}
	}
	
	public function clientTalkMsgSend(Client $client, $rid, $userNickname, $text, $ignore = false){
		if(isset($this->clients[$client->getId()])){
			$client = $this->clients[$client->getId()];
			$client->sendTalkMsg($rid, $userNickname, $text, $ignore);
		}
	}
	
	public function clientTalkUserNicknameChangeSend(Client $client, $userNicknameOld, $userNicknameNew){
		if(isset($this->clients[$client->getId()])){
			$client = $this->clients[$client->getId()];
			$client->sendTalkUserNicknameChange($userNicknameOld, $userNicknameNew);
		}
	}
	
	public function clientTalkCloseSend(Client $client, $rid, $userNickname){
		if(isset($this->clients[$client->getId()])){
			$client = $this->clients[$client->getId()];
			$client->sendTalkClose($rid, $userNickname);
		}
	}
	
	private function clientRemove(Client $client){
		$this->log->debug('client remove: '.$client->getId());
		
		if($client->getStatus('isChannelLocal') || $client->getStatus('isChannelPeer')){
			$this->consoleSetModeChannel(false);
			$this->consoleSetModeChannelClient(null);
			
			$this->consoleMsgAdd();
			$this->consoleMsgAdd('Connection to '.$client->getIpPort().' closed.', true, true);
		}
		
		$client->shutdown();
		
		$clientsId = $client->getId();
		unset($this->clients[$clientsId]);
	}
	
	public function connect($ip, $port, $clientActions = array()){
		$socket = new Socket();
		
		$connected = false;
		try{
			$connected = $socket->connect($ip, $port);
		}
		catch(Exception $e){
			$this->log->debug('connection to '.$ip.', '.$port.' failed: '.$e->getMessage());
		}
		
		if($connected){
			$client = $this->clientNew($socket);
			
			foreach($clientActions as $clientAction){
				$client->actionAdd($clientAction);
			}
			
			$client->sendHello();
			
			#ve($client);
			
			return true;
		}
		
		return false;
	}
	
	public function consoleMsgAdd($msgText = '', $showDate = false, $printPs1 = false, $clearLine = false){
		if($this->getKernel() && $this->getKernel()->getIpcConsoleConnection()){
			$this->getKernel()->getIpcConsoleConnection()->execAsync('msgAdd',
				array($msgText, $showDate, $printPs1, $clearLine));
		}
	}
	
	public function consoleSetModeChannel($modeChannel){
		if($this->getKernel() && $this->getKernel()->getIpcConsoleConnection()){
			$this->getKernel()->getIpcConsoleConnection()->execAsync('setModeChannel', array($modeChannel));
		}
	}
	
	public function consoleSetModeChannelClient($client){
		if($this->getKernel() && $this->getKernel()->getIpcConsoleConnection()){
			$this->getKernel()->getIpcConsoleConnection()->execAsync('setModeChannelClient', array($client));
		}
	}
	
}
