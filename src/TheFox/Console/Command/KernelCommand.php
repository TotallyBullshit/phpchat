<?php

namespace TheFox\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use TheFox\PhpChat\Kernel;

class KernelCommand extends BasicCommand{
	
	private $kernel;
	
	public function getPidfilePath(){
		return 'pid/kernel.pid';
	}
	
	protected function configure(){
		$this->setName('kernel');
		$this->setDescription('Run the Kernel.');
		$this->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run in daemon mode.');
		$this->addOption('shutdown', 's', InputOption::VALUE_NONE, 'Shutdown.');
	}
	
	protected function execute(InputInterface $input, OutputInterface $output){
		$this->executePre($input, $output);
		
		$this->log->info('kernel');
		$this->kernel = new Kernel();
		$this->kernel->loop();
		
		$this->executePost();
		$this->log->info('exit');
	}
	
	public function signalHandler($signal){
		$this->exit++;
		
		switch($signal){
			case SIGTERM:
				$this->log->notice('signal: SIGTERM');
				break;
			case SIGINT:
				print "\n";
				$this->log->notice('signal: SIGINT');
				break;
			case SIGHUP:
				$this->log->notice('signal: SIGHUP');
				break;
			case SIGQUIT:
				$this->log->notice('signal: SIGQUIT');
				break;
			case SIGKILL:
				$this->log->notice('signal: SIGKILL');
				break;
			case SIGUSR1:
				$this->log->notice('signal: SIGUSR1');
				break;
			default:
				$this->log->notice('signal: N/A');
		}
		
		$this->log->notice('main abort ['.$this->exit.']');
		
		if($this->kernel){
			$this->kernel->setExit($this->exit);
		}
		if($this->exit >= 2){
			exit(1);
		}
	}
	
}
