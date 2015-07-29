<?php

declare(ticks=1); 
//A very basic job daemon that you can extend to your needs. 
class MultiThread { 
    public static $processes = array();
    public static $processes_limit = 5;
    public static $max_system_load = 70;

    public static $signal_queue=array();   
    public static $parent_pid; 
   
    public function __construct(){ 
        self::$parent_pid = getmypid(); 
        pcntl_signal(SIGCHLD, array($this, "child_signal_handler")); 
    } 
   
    /** 
    * Run the Daemon 
    */ 
    public function run($function = false) {  
        echo "Running \n"; 
        for($i=0; $i<10000; $i++){ 
            $job_id = rand(0, 10000000000000); 

            while (count(self::$processes) >= self::$processes_limit){ 
            	$cores = self::get_system_cores();
				$load = self::get_system_load();

				$running_processes = count(self::$processes);
				$process_limit = self::$processes_limit;

				if ($load>self::$max_system_load) {
					self::$processes_limit++;
				} 

				echo "Maximum children allowed, waiting... (Cores: {$cores}) Load {$load}% | Processes {$running_processes}/{$process_limit}\n"; 
				sleep(1); 
            } 

            $launched = self::launch_job($job_id, $function); 
        } 
       
        //Wait for child processes to finish before exiting here 
        while (count(self::$processes)){ 
            echo "Waiting for current jobs to finish... \n"; 
            sleep(1); 
        } 
    } 
   
    /** 
    * Launch a job from the job queue 
    */ 
    protected function launch_job($job_id, $function = false) { 
        $pid = pcntl_fork(); 
        if ($pid == -1) { 
            //Problem launching the job 
            error_log('Could not launch new job ({$job_id}), exiting...'); 
            return false; 
        } else if ($pid) { 
            // Parent process 
            // Sometimes you can receive a signal to the childSignalHandler function before this code executes if 
            // the child script executes quickly enough! 
            // 
            self::$processes[$pid] = $job_id; 
           
            // In the event that a signal for this pid was caught before we get here, it will be in our signalQueue array 
            // So let's go ahead and process it now as if we'd just received the signal 
            if (isset(self::$signal_queue[$pid])){ 
                echo "found $pid in the signal queue, processing it now \n"; 
                self::child_signal_handler(SIGCHLD, $pid, self::$signal_queue[$pid]); 
                unset(self::$signal_queue[$pid]); 
            } 
        } else { 
            // Forked child, do your deeds.... 
            $exitStatus = 0; //Error code if you need to or whatever 
            echo "Running child process | jobid {$job_id} | pid ".getmypid()."\n"; 

            $function();

            exit($exitStatus); 
        } 
        return true; 
    } 
   
    public function child_signal_handler($signo, $pid=null, $status=null){ 
       
        //If no pid is provided, that means we're getting the signal from the system.  Let's figure out 
        //which child process ended 
        if (!$pid) { 
            $pid = pcntl_waitpid(-1, $status, WNOHANG); 
        } 
       
        //Make sure we get all of the exited children 
        while($pid > 0){ 
            if ($pid && isset(self::$processes[$pid])){ 

                $exitCode = pcntl_wexitstatus($status); 
                if ($exitCode != 0) { 
                    echo "$pid exited with status ".$exitCode."\n"; 
                } 
                unset(self::$processes[$pid]); 
            
            } else if($pid){ 
                //Oh no, our job has finished before this parent process could even note that it had been launched! 
                //Let's make note of it and handle it when the parent process is ready for it 
                echo "..... Adding $pid to the signal queue ..... \n"; 
                self::$signal_queue[$pid] = $status; 
            } 
            $pid = pcntl_waitpid(-1, $status, WNOHANG); 
        } 
        return true; 
    } 

    public static function get_system_load($coreCount = 2, $interval = 1){
	    $rs = sys_getloadavg();
	    $interval = ($interval >= 1 && 3 <= $interval) ? $interval : 1;
	    $load  = $rs[$interval];
	    return round(($load * 100) / $coreCount,2);
	}

	public static function get_system_cores() {
	    $cmd = "uname";
	    $OS = strtolower(trim(shell_exec($cmd)));
	 
	    switch($OS){
	       case('linux'):
	          $cmd = "cat /proc/cpuinfo | grep processor | wc -l";
	          break;
	       case('freebsd'):
	          $cmd = "sysctl -a | grep 'hw.ncpu' | cut -d ':' -f2";
	          break;
	       default:
	          unset($cmd);
	    }
	 
	    if ($cmd != ''){
	       $cpuCoreNo = intval(trim(shell_exec($cmd)));
	    }
	    return empty($cpuCoreNo) ? 1 : $cpuCoreNo;
	}
}

$MultiThread = new MultiThread();
$MultiThread->run(function() {
	for($i=0;$i<=1000;$i++) {
        // Do Task
	}
	echo "CHILD FUNCTION RAN\n";
	sleep(50);
});

