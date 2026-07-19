<?php
session_start();
if (!isset($_SESSION['zpuid'])) {
    die("<h1>Unathorised request!</h1><p>You must be logged in before you are able to view the DNS logs on this server.</p>");
}
if (!isset($_POST['csfr_token']) || !isset($_SESSION['zpcsfr']) || !hash_equals((string)$_SESSION['zpcsfr'], (string)$_POST['csfr_token'])) {
    die("<h1>Application Error: [0204]</h1><p>Invalid CSRF token. Accede al log desde el panel principal.</p>");
}
?>
<body bgcolor="#000000">
    <font color="#009900">
    <?php
    
		
		// Set Setnora DNS named/bind9 log file static to patch secerity issue. Will set to Database setting soon.
		$bindlog = "/var/bulwark/logs/bind/bind.log";
		
		
        $bindlog = str_replace('..', '__', $bindlog); 
        $logerror = array();
        $logwarning = array();
        $getlog = array();
        if (file_exists($bindlog)) {
            $handle = @fopen($bindlog, "r");
            $getlog = array();
            if ($handle) {
                while (!feof($handle)) {
                    	$buffer = fgets($handle, 4096);
                    	$getlog[] = $buffer;
                    	if (strstr($buffer, 'error:') || strstr($buffer, 'error ')) {
                        	$logerror[] = $buffer;
                    	}
                    	if (strstr($buffer, 'warning:') || strstr($buffer, 'warning ')) {
                        	$logwarning[] = $buffer;
                    	}
              	}fclose($handle);
        }
		


        if (isset($_POST['inViewErrors'])) {
            echo "<font color=\"#FFF\"><h2>BIND Errors:</h2></font>";
            foreach ($logerror as $logline) {
                $logline = str_replace("error", "<font color=\"#CC0000\">error</font>", $logline);
                echo $logline . "<br>";
            }
        }

        if (isset($_POST['inViewWarnings'])) {
            echo "<font color=\"#FFF\"><h2>BIND Warnings:</h2></font>";
            foreach ($logwarning as $logline) {
                $logline = str_replace("warning", "<font color=\"#FFFF99\">warning</font>", $logline);
                echo $logline . "<br>";
            }
        }

        if (isset($_POST['inViewLogs'])) {
            echo "<font color=\"#FFF\"><h2>BIND Full Logs:</h2></font>";
            foreach ($getlog as $logline) {
                if (strstr($logline, "succeeded") || strstr($logline, "SIGHUP")) {
                    $logline = "<font color=\"#00FF00\">" . $logline . "</font>";
                }
                if (strstr($logline, "error")) {
                    $logline = "<font color=\"#CC0000\">" . $logline . "</font>";
                }
                if (strstr($logline, "Failed")) {
                    $logline = "<font color=\"#AAAAAA\">" . $logline . "</font>";
                }
                if (strstr($logline, "warning")) {
                    $logline = "<font color=\"#FFFF99\">" . $logline . "</font>";
                }
                echo $logline . "<br>";
            }
        }
    } else {
        
    }
    ?>
    </font>
</body>