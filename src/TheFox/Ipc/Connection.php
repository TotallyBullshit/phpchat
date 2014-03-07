<?php

namespace TheFox\Ipc;

use Exception;
use OutOfBoundsException;

class Connection{
	
	const LOOP_USLEEP = 100000;
	const EXEC_SYNC_TIMEOUT = 5;
	
	private $isServer = false;
	private $handler = null;
	private $functions = array();
	private $execsId = 0;
	private $execs = array();
	
	public function __construct(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
	}
	
	public function isServer($isServer = null){
		if($isServer !== null){
			$this->isServer = $isServer;
		}
		
		return $this->isServer;
	}
	
	public function setHandler(AbstractHandler $handler){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
		
		$this->handler = $handler;
	}
	
	public function functionAdd($name, $objc = null, $func = null){
		if($objc !== null && $func === null){
			$func = $objc;
			$objc = null;
		}
		
		$this->functions[$name] = array(
			'name' => $name,
			'objc' => $objc,
			'func' => $func,
		);
	}
	
	public function functionExec($name, $args = array()){
		if(!isset($this->functions[$name])){
			throw new OutOfBoundsException('Function "'.$name.'" not defined.');
		}
		
		$function = $this->functions[$name];
		#array_unshift($args, $this);
		
		$objc = $this->objc;
		$func = $this->func;
		
		if($objc === null && $func === null){
			#print __CLASS__.'->'.__FUNCTION__.': null'."\n";
			return null;
		}
		elseif($objc === null && $func instanceof Closure){
			#print __CLASS__.'->'.__FUNCTION__.': exec anon "'.$name.'"'."\n";
			return call_user_func_array($func, $args);
		}
		elseif($objc === null && is_string($func)){
			#print __CLASS__.'->'.__FUNCTION__.': exec string "'.$func.'"'."\n";
			return call_user_func_array($func, $args);
		}
		elseif(is_object($objc) && is_string($func)){
			#print __CLASS__.'->'.__FUNCTION__.': exec objc'."\n";
			return call_user_func_array(array($objc, $func), $args);
		}
		elseif($objc === null && $func === null){
			#print __CLASS__.'->'.__FUNCTION__.': exec by name "'.$name.'"'."\n";
			return call_user_func_array($name, $args);
		}
	}
	
	private function execAdd($name, $args = array(), $retnFunc = null, $timeout = null, $type = null){
		$this->execsId++;
		
		$this->execs[$this->execsId] = array(
			'id' => $this->execsId,
			'name' => $name,
			'execRetn' => $retnFunc,
			'hasReturned' => false,
			'timeout' => $timeout,
			'value' => null,
			'type' => $type, // [a]sync, [s]ync
		);
		
		return $this->execsId;
	}
	
	public function exec($name, $args = array(), $retnFunc = null){
		$this->execAsync($name, $args, $retnFunc);
	}
	
	public function execAsync($name, $args = array(), $retnFunc = null){
		$execsId = $this->execAdd($name, $args, $retnFunc, null, 'a');
		
		$this->handler->sendFunctionExec($name, $args, $execsId);
	}
	
	public function execSync($name, $args = array(), $timeout = null){
		if($timeout === null){
			$timeout = static::EXEC_SYNC_TIMEOUT;
		}
		
		$execsId = $this->execAdd($name, $args, null, $timeout, 's');
		$this->handler->sendFunctionExec($name, $args, $execsId);
		
		$start = time();
		while( time() - $timeout <= $start && !$this->execs[$execsId]['hasReturned'] ){
			$this->run();
			usleep(static::LOOP_USLEEP);
		}
		
		$value = $this->execs[$execsId]['value'];
		unset($this->execs[$execsId]);
		#print "remove B $execsId\n";
		
		return $value;
	}
	
	public function wait(){
		$break = false;
		do{
			$this->run();
			
			$break = !count($this->execs);
			
			usleep(static::LOOP_USLEEP);
		}while(!$break);
	}
	
	public function connect(){
		if($this->handler === null){
			throw new Exception('Handler not set. Use setHandler().');
		}
		
		if($this->isServer()){
			try{
				$this->handler->listen();
				return true;
			}
			catch(Exception $e){
				return false;
			}
		}
		else{
			return $this->handler->connect();
		}
	}
	
	private function msgHandle($msg, $clientId = null){
		print __CLASS__.'->'.__FUNCTION__.': "'.$msg.'"'."\n";
		
		if($msg == 'ID'){
			$this->handler->sendIdOk($clientId);
		}
		elseif(substr($msg, 0, 14) == 'FUNCTION_EXEC '){
			$data = substr($msg, 14);
			$json = json_decode($data, true);
			
			$args = array();
			$argsIn = $json['args'];
			foreach($argsIn as $arg){
				$args[] = unserialize($arg);
			}
			
			try{
				$value = $this->functionExec($json['name'], $args);
				$this->handler->sendFunctionRetn($value, $json['rid'], $clientId);
			}
			catch(Exception $e){
				print __CLASS__.'->'.__FUNCTION__.': '.$e->getMessage().''."\n";
			}
		}
		elseif(substr($msg, 0, 14) == 'FUNCTION_RETN '){
			$data = substr($msg, 14);
			$json = json_decode($data, true);
			
			#print "value: '". $json['value'] ."'\n";
			$value = unserialize($json['value']);
			#print "value: '". \TheFox\Utilities\Hex::dataEncode($value) ."'\n";
			$rid = (int)$json['rid'];
			
			if(array_key_exists($rid, $this->execs)){
				$this->execs[$rid]['value'] = $value;
				$this->execs[$rid]['hasReturned'] = true;
				
				if($this->execs[$rid]['execRetn']){
					$func = $this->execs[$rid]['execRetn'];
					$func($value);
				}
				
				if($this->execs[$rid]['type'] == 'a'){
					#print "remove A $rid\n";
					unset($this->execs[$rid]);
				}
			}
		}
	}
	
	public function run(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
		if($this->handler === null){
			throw new Exception('Handler not set. Use setHandler().');
		}
		
		$this->handler->run();
		
		if($this->isServer()){
			foreach($this->handler->recvBuffer() as $client){
				#print __CLASS__.'->'.__FUNCTION__.': client '.$client['id']."\n";
				foreach($client['recvBuffer'] as $msg){
					$this->msgHandle($msg, $client['id']);
				}
			}
		}
		else{
			foreach($this->handler->recvBuffer() as $msg){
				$this->msgHandle($msg);
			}
		}
	}
	
	public function loop(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
		
		while(true){
			$this->run();
			usleep(static::LOOP_USLEEP);
		}
	}
	
}
