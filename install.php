<?php

#Change time out to 5 seconds (default is 60)
ini_set('default_socket_timeout', 5);

#If config exists then redirect to the app
if (file_exists("config.php")) header("Location: index.php");

#If an action is new_config and config file does not exist then process the information
if (isset($_REQUEST['action']) && $_REQUEST['action'] == "new_config" && !file_exists("config.php")) {
    new_config();
    exit();
}

#Detect local interval program
$localPi = isValidUrl("http://127.0.0.1:8080");

#New config setup
function new_config() {
    #Begin creation of config.php
    $config = "<?php\n";

    #Define all the required variables for config.php
    $needed = array("os_ip","os_pw","pass_file","cache_file","log_file");

    #Cycle through each needed key
    foreach ($needed as $key) {

        #If required variable is not submitted then fail
        if (!isset($_REQUEST[$key])) fail();

        $data = $_REQUEST[$key];

        #If processing OS IP then check if the IP is valid and if not, fail with error code 2
        if ($key == "os_ip" && !isValidUrl("http://".$data)) {
            echo 2; exit();
        }

        #If processing password file then go ahead and generate it with proper username/password hash
        if ($key == "pass_file") {

            #If username or password is not submitted fail
            if (!isset($_REQUEST["username"]) || !isset($_REQUEST["password"])) fail();

            $file = fopen($data, 'w');

            #If unable to open the pass file fail
            if (!$file) {
                fail();
            } else {
                $r = fwrite($file,$_REQUEST["username"].":".base64_encode(sha1($_REQUEST["password"])));
                if (!$r) fail();
                fclose($file);
            }
        }

        #Attempt to make the cache file and log file file
        if ($key == "cache_file" || $key == "log_file") make_file($data);

        #Append current key/data pair to config.php string.
        $config .= "$".$key." = '".$data."';\n";            
    }

    if (isset($_REQUEST["force_ssl"])) {
        $config .= "\$force_ssl=1;\n";
    } else {
        $config .= "\$force_ssl=0;\n";        
    }

    #Attempt to open config.php for writing
    $file = fopen("config.php", 'w');

    #If unable, fail
    if (!$file) fail();

    #Write the config out
    $r = fwrite($file,$config."?>");

    #If unable to write the config, fail
    if (!$r) fail();

    try {
        #Add the watcher for logs to crontab
        $output = shell_exec('crontab -l');
        if (strpos($output,"/watcher.php >/dev/null 2>&1") === false) {
            file_put_contents('/tmp/crontab.txt', $output.'* * * * * cd '.dirname(__FILE__).'; php '.dirname(__FILE__).'/watcher.php >/dev/null 2>&1'.PHP_EOL);
            exec('crontab /tmp/crontab.txt');
        }
    } catch (Exception $e) {
        echo 3; exit();        
    }

    #Tell javascript action was succesful
    echo 1;
}

#Check if URL is valid by grabbing headers and verifying reply is: 200 OK
function isValidUrl($url) {
    $data = file_get_contents($url."/vs");
    if ($data === false) return false;

    preg_match("/<script>.*?snames=/",$data,$test);
    if (empty($test)) return false;
    
    return true;
}

#Attempt to make file or fail if unable
function make_file($data) {
    $file = fopen($data, "w");
    if (!$file) fail();
    fclose($file);    
}

#Fail by returning error code 0
function fail() {
    echo 0;
    exit();
}

?>

<!DOCTYPE html>
<html>
	<head>
    	<title>New Install</title> 
        <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
        <meta name="viewport" content="initial-scale=1.0,user-scalable=no,maximum-scale=1" media="(device-height: 568px)" />
        <meta content="yes" name="apple-mobile-web-app-capable">
        <meta name="apple-mobile-web-app-title" content="Sprinklers">
        <link rel="apple-touch-icon" href="img/icon.png">
    	<link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/jquery-mobile/1.3.2/jquery.mobile.min.css" id="theme" />
        <style type="text/css">
            .desc {
                font-size:smaller;
                text-align:center;
                word-wrap:normal;
                white-space:normal;
                overflow:visible;
            }
        </style>
        <script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>
        <script src="//cdnjs.cloudflare.com/ajax/libs/jquery-mobile/1.3.2/jquery.mobile.min.js"></script>
        <script>
            //After jQuery mobile is loaded set intial configuration
            $(document).one("mobileinit", function(e){
                $.mobile.defaultPageTransition = 'fade';
                $.mobile.defaultDialogTransition = 'fade';
                $.mobile.hashListeningEnabled = false;
                var theme = localStorage.getItem("theme");
                if (theme === null) {
                    theme = "legacy";
                    localStorage.setItem("theme",theme)
                }
                $("#theme").attr("href",getThemeUrl(theme));
            });
            function getThemeUrl(theme) {
                switch (theme) {
                    case "flat":
                        var url = "css/jquery.mobile.flatui.min.css";
                        break;
                    default:
                        var url = "//cdnjs.cloudflare.com/ajax/libs/jquery-mobile/1.3.2/jquery.mobile.min.css";
                        break;
                }
                return url;
            }
            function showerror(msg) {
                // show error message
                $.mobile.loading( 'show', {
                    text: msg,
                    textVisible: true,
                    textonly: true,
                    theme: 'c'
                });
            }
            function submit_config() {
                $.mobile.showPageLoadingMsg()
                //Submit form data to the server
                $.get("install.php","action=new_config&"+$("#options").find(":input").serialize(),function(data){
                    $.mobile.hidePageLoadingMsg()
                    if (data == 1) {
                        //If successful
                        showerror("Settings have been saved. Please wait while your redirected to the login screen!")
                        setTimeout(function(){location.reload()},2500);
                    } else if (data == 3) {
                        //Crontab not added but everything else went fine
                        showerror("Settings have been saved. However, crontab was not added and must be added manually.")
                        setTimeout(function(){location.reload()},2500);
                    } else {
                        if (data == 2) {
                            //URL Invalid
                            showerror("Settings have NOT been saved. Check IP and Port settings and try again.")
                        } else {
                            //Probably permission error or required key not submitted
                            showerror("Settings have NOT been saved. Check folder permissions and file paths then try again.")
                        }
                        setTimeout(function(){$.mobile.loading('hide')}, 2500);                    
                    }
                })
            }
        </script>
    </head> 
    <body>
        <div data-role="page" id="install" data-close-btn="none">
        	<div data-role="header" data-position="fixed">
                <h1>New Install</h1>
                <a href="javascript:submit_config()" class="ui-btn-right">Submit</a>
           </div>
        	<div data-role="content">
                <form action="javascript:submit_config()" method="post" id="options">
                    <ul data-inset="true" data-role="listview">
                        <li data-role="list-divider">Add New User</li>
                        <li>
                            <p class='desc'>You can add additional users after logging in</p>
                            <div data-role="fieldcontain">
                                <label for="username">Username:</label>
                                <input autocapitalize="off" autocorrect="off" type="text" name="username" id="username" value="" />
                                <label for="password">Password:</label>
                                <input type="password" name="password" id="password" value="" />
                            </div>
                        </li>
                    </ul>
                    <div data-role="collapsible-set">
                        <fieldset data-role="collapsible" <?php echo $localPi ? "data-theme='a'" : "data-collapsed='false' data-theme='b'"; ?>>
                            <legend><?php echo $localPi ? "Interval Program (detected)" : "OpenSprinkler IP/password"; ?></legend>
                            <div data-role="fieldcontain">
                                <label for="os_ip">Open Sprinkler IP:</label>
                                <input type="text" name="os_ip" id="os_ip" <?php echo $localPi ? "value='127.0.0.1:8080'" : ""; ?> />
                                <label for="os_pw">Open Sprinkler Password:</label>
                                <input type="password" name="os_pw" id="os_pw" <?php echo $localPi ? "value='opendoor'" : ""; ?> />
                            </div>
                        </fieldset>
                        <fieldset data-role="collapsible" data-theme="a">
                            <legend>Advanced Configuration</legend>
                            <div data-role="fieldcontain">
                                <label for="pass_file">Pass File Location:</label>
                                <input type="text" name="pass_file" id="pass_file" value="<?php echo dirname(__FILE__); ?>/.htpasswd" />
                                <label for="cache_file">Cache File Location:</label>
                                <input type="text" name="cache_file" id="cache_file" value="<?php echo dirname(__FILE__); ?>/.cache" />
                                <label for="log_file">Sprinkler Log File:</label>
                                <input type="text" name="log_file" id="log_file" value="<?php echo dirname(__FILE__); ?>/SprinklerChanges.txt" />
                                <label for="force_ssl">Force SSL</label>
                                <input type="checkbox" name="force_ssl" id="force_ssl" />
                            </div>
                        </fieldset>
                    </div>
                    <input type="submit" value="Submit" />
                </form>
            </div>
        </div>
    </body>
</html>
