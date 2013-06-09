<?php

#Refuse if a direct call has been made
if(!defined('Sprinklers')){echo $denied;exit();}

#Include configuration
require_once("config.php");

if (isset($_SERVER['SERVER_NAME'])) $base_url = "https://".$_SERVER['SERVER_NAME'].$_SERVER['PHP_SELF'];

#Call action if requested and allowed
if (isset($_REQUEST['action'])) {
	if (is_callable($_REQUEST['action'])) {
		if (($_REQUEST['action'] == "gettoken" || $_REQUEST['action'] == "checktoken" || $_REQUEST['action'] == "login") || is_auth()) {
			call_user_func($_REQUEST['action']);
		}
		exit();
	} else {
		exit();
	}
}

#OpenSprinkler functions
function get_stations() {
    global $os_ip;
    $stations = file_get_contents("http://".$os_ip."/pn.js");
    $stations = substr($stations, 12, strlen($stations) - 15);
    $stations = str_replace("'", "", $stations);
    $stations = explode(",", $stations);
    return $stations;
}

function get_programs() {
    global $os_ip;
    $data = file_get_contents("http://".$os_ip."/gp?d=0");
    preg_match("/<script>([\w\W]*?)<\/script>/", $data, $matches);
    preg_match("/var nprogs=(\d+),nboards=(\d+),ipas=\d+,mnp=\d+,pd=\[\];(.*);/", $matches[1], $matches);
    $newdata = array("nprogs" => $matches[1], "nboards" => $matches[2]);
    $progs = explode(";", $matches[3]);
    $i = 0;
    foreach ($progs as $prog) {
        $tmp = explode("=", $prog);
        $tmp2 = str_replace("[", "", $tmp[1]);
        $tmp2 = str_replace("]", "", $tmp2); 
        $program = explode(",", $tmp2);

        #Reset variables
        $days0 = $program[1]; $days1 = $program[2]; $even = false; $odd = false; $interval = false; $days = ""; $stations = "";

        $newdata["programs"][$i]["en"] = $program[0];
        $newdata["programs"][$i]["start"] = $program[3];
        $newdata["programs"][$i]["end"] = $program[4];
        $newdata["programs"][$i]["interval"] = $program[5];
        $newdata["programs"][$i]["duration"] = $program[6];

        for ($n=0; $n < $newdata["nboards"]; $n++) {
            $stations .= strval(decbin($program[7+$n]));
        }

        $newdata["programs"][$i]["stations"] = $stations;

        if(($days0&0x80)&&($days1>1)){
            // this is an interval program
            $days=array($days1,$days0&0x7f);
            $interval = true;
        } else {
            // this is a weekly program 
            for($d=0;$d<7;$d++) {
                if ($days0&(1<<$d)) {
                    $days .= "1";
                } else {
                    $days .= "0";
                }
            }
            if(($days0&0x80)&&($days1==0))  {$even = true;}
            if(($days0&0x80)&&($days1==1))  {$odd = true;}
        }

        $newdata["programs"][$i]["days"] = $days;
        $newdata["programs"][$i]["is_even"] = $even;
        $newdata["programs"][$i]["is_odd"] = $odd;
        $newdata["programs"][$i]["is_interval"] = $interval;
        $i++;
    }
    return $newdata;
}

function update_program() {
    global $os_ip, $os_pw;
    file_get_contents("http://".$os_ip."/cp?pw=".$os_pw."&pid=".$_REQUEST["pid"]."&v=".$_REQUEST["data"]);
}

function delete_program() {
    global $os_ip, $os_pw;
    file_get_contents("http://".$os_ip."/dp?pw=".$os_pw."&pid=".$_REQUEST["pid"]);
}

function get_settings() {
    global $os_ip;
    $data = file_get_contents("http://".$os_ip);
    preg_match("/<script>([\w\W]*?)<\/script>/", $data, $matches);
    $data = str_replace("var ", "", $matches[1]);
    $data = preg_replace("/ps=.*;/", "", $data);
    $data = preg_replace("/sbits=.*;/", "", $data);
    $data = preg_replace("/lrun=\[.*\]/", "", $data);
    $data = str_replace(";", ",", $data);
    $data = str_replace("\n", "", $data);
    $data = explode(",", $data);
    $newdata = array();
    foreach ($data as $variable) {
        if ($variable === "") continue;
        $tmp = explode("=", $variable);
        $newdata[$tmp[0]] = $tmp[1];
    }
    return $newdata;
}

function runonce() {
    global $os_ip, $os_pw;
    file_get_contents("http://".$os_ip."/cr?pw=".$os_pw."&t=".$_REQUEST["data"]);    
}

function raindelay() {
    global $os_ip, $os_pw;
    file_get_contents("http://".$os_ip."/cv?pw=".$os_pw."&rd=".$_REQUEST["delay"]);
}

function rsn() {
    global $os_ip, $os_pw;
    file_get_contents("http://".$os_ip."/cv?pw=".$os_pw."&rsn=1");
}

function rbt() {
    global $os_ip, $os_pw;
    file_get_contents("http://".$os_ip."/cv?pw=".$os_pw."&rbt=1");
}

function en_on() {
    global $os_ip, $os_pw;
    file_get_contents("http://".$os_ip."/cv?pw=".$os_pw."&en=1");
}

function en_off() {
    global $os_ip, $os_pw;
    file_get_contents("http://".$os_ip."/cv?pw=".$os_pw."&en=0");
}

function mm_on() {
    global $os_ip, $os_pw;
    file_get_contents("http://".$os_ip."/cv?pw=".$os_pw."&mm=1");
}

function mm_off() {
    global $os_ip, $os_pw;
    file_get_contents("http://".$os_ip."/cv?pw=".$os_pw."&mm=0");
}

function spon() {
    global $os_ip;
    file_get_contents("http://".$os_ip."/sn".$_REQUEST["zone"]."=1");
}

function spoff() {
    global $os_ip;
    file_get_contents("http://".$os_ip."/sn".$_REQUEST["zone"]."=0");
}

#Content generation functions
function is_en() {
    $settings = get_settings();
    if ($settings["en"] == 1) return "selected";
}

function is_mm() {
    $settings = get_settings();
    if ($settings["mm"] == 1) return "selected";
}

function show_logs() {
#Adapted from the script written by David B. Gustavson, 20121021
    global $timeViewWindow, $log_file;

    $ValveName = get_stations();

    $SprinklerValveHistory=file_get_contents($log_file);
    $timeEarliest=strtotime(Date("Y-m-d H:i:s",strtotime("-".$timeViewWindow,time())));
    $Lines=explode("\n",$SprinklerValveHistory);

    for ($i=0;$i<count($Lines);$i++){
        $ELines[$i]=explode("--",$Lines[$i]);
        if (count($ELines[$i])>1){
            $timeThis=strtotime($ELines[$i][1]);
            if ($timeThis>$timeEarliest){
                $SprinklerPattern[]=str_split($ELines[$i][0]);
                $SprinklerTime[]=$ELines[$i][1];
                $SprinklerTimeConverted[]=strtotime($ELines[$i][1]);
            };
        };
    };

    for ($i=0;$i<count($SprinklerPattern);$i++){
        $ResultLine=" ";
        for ($j=0;$j<16;$j++){
            if (($i>0) && ($SprinklerPattern[$i-1][$j]=="1") && ($SprinklerPattern[$i][$j]=="0")|| ($i==count($SprinklerPattern)-1) && ($SprinklerPattern[$i][$j]=="1")) {
                $TimeNow = $SprinklerTimeConverted[$i];
                $TimeBegin = $TimeNow;

                for ($k=1;$k<$i;$k++) {
                    if ($SprinklerPattern[$i-$k][$j]=="1"){
                        $TimeBegin=$SprinklerTimeConverted[$i-$k];
                    } else { break; };
                };

                $TimeElapsed=$TimeNow-$TimeBegin;

                $ResultLine.=" ".$ValveName[$j].((($i==count($SprinklerPattern)-1) && ($SprinklerPattern[$i][$j]=="1")) ? " has been on for ":" was on for ").$TimeElapsed." seconds.  ";
                 
                $ValveHistory[$j][]= array($SprinklerTime[$i], $TimeElapsed, ((($i==count($SprinklerPattern)-1)&&($SprinklerPattern[$i][$j]=="1")) ? " Running Now" : ""));
            };
        };
    };
    $logtable = "<table data-mode='reflow' class='ui-responsive table-stroke' data-role='table'><thead><tr><th data-priority='1'>Zone</th><th data-priority='2'>Duration</th><th data-priority='3'>Run Time</th></tr></thead><tbody>";
    for ($j=0;$j<16;$j++) {
        if (!isset($ValveHistory[$j])) continue;
        $ct=count($ValveHistory[$j]);
        if ($ct>0) {
            for ($k=0;$k<count($ValveHistory[$j]);$k++){
                $theTime=date_format(date_create($ValveHistory[$j][$k][0]), 'D, M j, Y, g:i A');
                $logtable .= "<tr><th>".$ValveName[$j]."</th><td>".number_format(($ValveHistory[$j][$k][1]/60), 1, '.', ',')." min</td><td>".$theTime.$ValveHistory[$j][$k][2]."</td></tr>";
            };
        };
    };

    $logtable .= "</tbody></table>";
    return $logtable;
}

function make_runonce() {
    $list = "<p align='center'>Value is in minutes. Zero means the station will be excluded from the run-once program.</p><div data-role='fieldcontain'>";
    $n = 0;
    $stations = get_stations();
    foreach ($stations as $station) {
        if ($station === "") continue;
        $list .= "<label for='zone-".$n."'>".$station.":</label><input type='number' data-highlight='true' data-type='range' name='zone-".$n."' min='0' max='30' id='zone-".$n."' value='0'>";
        $n++;
    }
    return $list."</div><button onclick='submit_runonce()'>Submit</button>";
}

function make_list_programs() {
    $data = get_programs();
    $stations = get_stations();
    $list = "";
    $week = array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");
    $n = 0;
    foreach ($data["programs"] as $program) {
        if (is_array($program["days"])) {
            $days = $program["days"];
        } else {
            $days = str_split($program["days"]);
        }
        $set_stations = str_split($program["stations"]);
        $list .= "<fieldset id='program-".$n."' data-inset='false' data-role='collapsible' data-theme='b' data-content-theme='d'><legend>Program ".($n + 1)."</legend>";

        $list .= "<input type='checkbox' ".(($program["en"]) ? "checked='checked'" : "")." name='en-".$n."' id='en-".$n."'><label for='en-".$n."'>Enabled</label>";
        $list .= "<fieldset data-role='controlgroup' data-type='horizontal' style='text-align: center'>";
        $list .= "<input type='radio' name='rad_days-".$n."' id='days_week-".$n."' value='days_week-".$n."' ".(($program["is_interval"]) ? "" : "checked='checked'")."><label for='days_week-".$n."'>Weekly</label>";
        $list .= "<input type='radio' name='rad_days-".$n."' id='days_n-".$n."' value='days_n-".$n."' ".(($program["is_interval"]) ? "checked='checked'" : "")."><label for='days_n-".$n."'>Interval</label>";
        $list .= "</fieldset><div id='input_days_week-".$n."' ".(($program["is_interval"]) ? "style='display:none'" : "").">";

        $list .= "<fieldset data-role='controlgroup' data-type='horizontal' style='text-align: center'><p>Restrictions</p>";
        $list .= "<input type='radio' name='rad_rst-".$n."' id='days_norst-".$n."' value='days_norst-".$n."' ".((!$program["is_even"] && !$program["is_odd"]) ? "checked='checked'" : "")."><label for='days_norst-".$n."'>None</label>";
        $list .= "<input type='radio' name='rad_rst-".$n."' id='days_odd-".$n."' value='days_odd-".$n."' ".((!$program["is_even"] && $program["is_odd"]) ? "checked='checked'" : "")."><label for='days_odd-".$n."'>Odd</label>";
        $list .= "<input type='radio' name='rad_rst-".$n."' id='days_even-".$n."' value='days_even-".$n."' ".((!$program["is_odd"] && $program["is_even"]) ? "checked='checked'" : "")."><label for='days_even-".$n."'>Even</label>";
        $list .= "</fieldset>";

        $list .= "<fieldset data-role='controlgroup'><legend>Days:</legend>";
        $j = 0;            
        foreach ($week as $day) {
            $list .= "<input type='checkbox' ".((!$program["is_interval"] && $days[$j]) ? "checked='checked'" : "")." name='d".$j."-".$n."' id='d".$j."-".$n."'><label for='d".$j."-".$n."'>".$day."</label>";
            $j++;
        }
        $list .= "</fieldset></div>";

        $list .= "<div ".(($program["is_interval"]) ? "" : "style='display:none'")." id='input_days_n-".$n."' class='ui-grid-a'>";
        $list .= "<div class='ui-block-a'><label for='every-".$n."'>Day Interval</label><input type='number' name='every-".$n."' pattern='[0-9]*' id='every-".$n."' value='".$days[0]."'></div>";
        $list .= "<div class='ui-block-b'><label for='starting-".$n."'>Starting In</label><input type='number' name='starting-".$n."' pattern='[0-9]*' id='starting-".$n."' value='".$days[1]."'></div>";
        $list .= "</div>";

        $list .= "<fieldset data-role='controlgroup'><legend>Stations:</legend>";
        $j = 0;
        foreach ($stations as $station) {
            if ($station === "") continue;
            $list .= "<input type='checkbox' ".(($set_stations[$j]) ? "checked='checked'" : "")." name='station_".$j."-".$n."' id='station_".$j."-".$n."'><label for='station_".$j."-".$n."'>".$station."</label>";
            $j++;
        }
        $list .= "</fieldset>";

        $list .= "<fieldset data-role='controlgroup' data-type='horizontal' style='text-align: center'>";
        $list .= "<input type='reset' name='s_checkall-".$n."' id='s_checkall-".$n."' value='Check All' />";
        $list .= "<input type='reset' name='s_uncheckall-".$n."' id='s_uncheckall-".$n."' value='Uncheck All' />";
        $list .= "</fieldset>";

        $list .= "<div class='ui-grid-a'>";
        $list .= "<div class='ui-block-a'><label for='start-".$n."'>Start Time</label><input type='time' name='start-".$n."' id='start-".$n."' value='".gmdate("H:i", $program["start"]*60)."'></div>";
        $list .= "<div class='ui-block-b'><label for='end-".$n."'>End Time</label><input type='time' name='end-".$n."' id='end-".$n."' value='".gmdate("H:i", $program["end"]*60)."'></div>";
        $list .= "</div>";

        $list .= "<label for='duration-".$n."'>Duration (minutes)</label><input type='number' data-highlight='true' data-type='range' name='duration-".$n."' min='0' max='30' id='duration-".$n."' value='".($program["duration"]/60)."'>";

        $list .= "<input type='submit' name='submit-".$n."' id='submit-".$n."' value='Save Changes to Program ".($n + 1)."'>";
        $list .= "<input data-theme='a' type='submit' name='delete-".$n."' id='delete-".$n."' value='Delete Program ".($n + 1)."'></fieldset>";

        $n++;
    }
    echo $list;
}

function fresh_program() {
    $stations = get_stations();
    $week = array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");

    $list = "<fieldset id='program-new' data-theme='b' data-content-theme='d'><input type='checkbox' name='en-new' id='en-new'><label for='en-new'>Enabled</label>";
    $list .= "<fieldset data-role='controlgroup' data-type='horizontal' style='text-align: center'>";
    $list .= "<input type='radio' name='rad_days-new' id='days_week-new' value='days_week-new' checked='checked'><label for='days_week-new'>Weekly</label>";
    $list .= "<input type='radio' name='rad_days-new' id='days_n-new' value='days_n-new'><label for='days_n-new'>Interval</label>";
    $list .= "</fieldset><div id='input_days_week-new'>";

    $list .= "<fieldset data-role='controlgroup' data-type='horizontal' style='text-align: center'><p>Restrictions</p>";
    $list .= "<input type='radio' name='rad_rst-new' id='days_norst-new' value='days_norst-new' checked='checked'><label for='days_norst-new'>None</label>";
    $list .= "<input type='radio' name='rad_rst-new' id='days_odd-new' value='days_odd-new'><label for='days_odd-new'>Odd</label>";
    $list .= "<input type='radio' name='rad_rst-new' id='days_even-new' value='days_even-new'><label for='days_even-new'>Even</label>";
    $list .= "</fieldset>";

    $list .= "<fieldset data-role='controlgroup'><legend>Days:</legend>";
    $j = 0;            
    foreach ($week as $day) {
        $list .= "<input type='checkbox' name='d".$j."-new' id='d".$j."-new'><label for='d".$j."-new'>".$day."</label>";
        $j++;
    }
    $list .= "</fieldset></div>";

    $list .= "<div style='display:none' id='input_days_n-new' class='ui-grid-a'>";
    $list .= "<div class='ui-block-a'><label for='every-new'>Day Interval</label><input type='number' name='every-new' pattern='[0-9]*' id='every-new'></div>";
    $list .= "<div class='ui-block-b'><label for='starting-new'>Starting In</label><input type='number' name='starting-new' pattern='[0-9]*' id='starting-new'></div>";
    $list .= "</div>";
    $list .= "<fieldset data-role='controlgroup'><legend>Stations:</legend>";
    $j = 0;
    foreach ($stations as $station) {
        if ($station === "") continue;
        $list .= "<input type='checkbox' name='station_".$j."-new' id='station_".$j."-new'><label for='station_".$j."-new'>".$station."</label>";
        $j++;
    }
    $list .= "</fieldset>";

    $list .= "<fieldset data-role='controlgroup' data-type='horizontal' style='text-align: center'>";
    $list .= "<input type='reset' name='s_checkall-new' id='s_checkall-new' value='Check All' />";
    $list .= "<input type='reset' name='s_uncheckall-new' id='s_uncheckall-new' value='Uncheck All' />";
    $list .= "</fieldset>";

    $list .= "<div class='ui-grid-a'>";
    $list .= "<div class='ui-block-a'><label for='start-new'>Start Time</label><input type='time' name='start-new' id='start-new'></div>";
    $list .= "<div class='ui-block-b'><label for='end-new'>End Time</label><input type='time' name='end-new' id='end-new'></div>";
    $list .= "</div>";

    $list .= "<label for='duration-new'>Duration (minutes)</label><input type='number' data-highlight='true' data-type='range' name='duration-new' min='0' max='30' id='duration-new'>";

    $list .= "<input type='submit' name='submit-new' id='submit-new' value='Save New Program'>";
    echo $list;
}


function make_list_manual() {
    $list = '<li data-role="list-divider">Sprinkler Stations</li>';
    $stations = get_stations();
    foreach ($stations as $station) {
        if ($station === "") continue;
        $list .= '<li><a href="javascript:toggle()">'.$station.'</a></li>';
    }
    return $list;
}

function make_list_status() {
    global $os_ip;

    $settings = get_settings();

    $tz = $settings['tz']-48;
    $tz = (($tz>=0) ? "+" : "-").(abs($tz)/4).":".((abs($tz)%4)*15/10).((abs($tz)%4)*15%10);

    $list = '<li data-role="list-divider">Device Time</li><li>'.gmdate("D, d M Y H:i:s",$settings["devt"]).'</li>';

    $list .= '<li data-role="list-divider">Time Zone</li><li>GMT '.$tz.'</li>';

    $fw = join(".",str_split($settings["ver"]));
    $list .= '<li data-role="list-divider">Firmware Version</li><li>'.$fw.'</li>';

    $list .= '<li data-role="list-divider">System Enabled</li><li>'.(($settings["en"]==1) ? "Yes" : "No").'</li>';

    $list .= '<li data-role="list-divider">Rain Delay</li><li>'.(($settings["rd"]==0) ? "No" : "Until ".gmdate("D, d M Y H:i:s",$settings["rdst"])).'</li>';

    $list .= '<li data-role="list-divider">Sprinkler Stations</li>';
    $stations = get_stations();
    $status = str_split(file_get_contents("http://".$os_ip."/sn0"));
    $i = 0;
    foreach ($stations as $station) {
        if ($station === "") continue;
        if ($status[$i]) {
            $color = "green";
        } else {
            $color = "red";
        }
        $list .= '<li class="'.$color.'">'.$station.'</li>';
        $i++;
    }
    echo $list;
}

function make_panel($page) {
    $buttons = array(
        "Logout" => array(
            "icon" => "delete",
            "url" => "javascript:logout()"
        )
    );
    $opts = '';
    $panel = '<div data-role="panel" id="'.$page.'-settings" data-theme="a"'.$opts.'><ul data-role="listview" data-theme="a"><li>Logged in as: '.$_SESSION["username"].'</li><li><div class="ui-grid-a"><div class="ui-block-a"><br><label for="autologin">Auto Login</label></div><div class="ui-block-b"><select name="autologin" id="'.$page.'-autologin" data-role="slider"><option value="off">Off</option><option value="on">On</option></select></div></li>';
    foreach ($buttons as $button => $data) {
        if ($data["url"] == "close") {
            $url = '#" data-rel="close';
        } else {
            $url = $data["url"];
        }
        $panel .= '<li data-icon="'.$data["icon"].'"><a href="'.$url.'">'.$button.'</a></li>';
    }
    $panel .= '</ul></div>';
    return $panel;
}

#Authentication functions
function http_authenticate($user,$pass,$crypt_type='SHA'){
    global $pass_file;

    if (!ctype_alnum($user)) return FALSE;
    if (!ctype_alnum($pass)) return FALSE;

    if(file_exists($pass_file) && is_readable($pass_file)){
        if($fp=fopen($pass_file,'r')){
            while($line=fgets($fp)){
                $line=preg_replace('`[\r\n]$`','',$line);
                list($fuser,$fpass)=explode(':',$line);
                if($fuser==$user){
                    switch($crypt_type){
                        case 'DES':
                            $salt=substr($fpass,0,2);
                            $test_pw=crypt($pass,$salt);
                            break;
                        case 'PLAIN':
                            $test_pw=$pass;
                            break;
                        case 'SHA':
                            $test_pw=base64_encode(sha1($pass));
                            break;
                        case 'MD5':
                            $test_pw=md5($pass);
                            break;
                        default:
                            fclose($fp);
                            return FALSE;
                    }
                    if($test_pw == $fpass){
                        fclose($fp);
                        return TRUE;
                    }else{
                        fclose($fp);
                        return FALSE;
                    }
                }
            }
            fclose($fp);
        }else{
            return FALSE;
        }
    }else{
        return FALSE;
    }
}
function gettoken() {
    if (is_auth() && isset($_SESSION["token"])) {
        echo $_SESSION["token"];
        return;
    }
    login("token");
}

function login($tosend = "sprinklers") {
    global $denied, $webtitle, $cache_file;

    $starttime = explode(' ', microtime()); 
    $starttime = $starttime[1] + $starttime[0]; 
    
    $auth = base64_encode(hash("sha256",$_SERVER['REMOTE_ADDR']).hash("sha256",$starttime).hash("sha256",$_POST['username']));
    if (!http_authenticate($_POST['username'],$_POST['password'])) {
        echo 0; 
        exit();
    } else {
        if (isset($_POST['remember']) && $_POST['remember'] == "true") {
            $fh = fopen($cache_file, 'a+');
            fwrite($fh, $starttime." ".$auth." ".$_POST['username']."\n");
            fclose($fh);
            $_SESSION['sendtoken'] = true;
        }
        $_SESSION['token'] = $auth;
        $_SESSION['isauth'] = 1;
        $_SESSION['username'] = $_POST['username'];
        
        if ($tosend == "token") {
            if (isset($_SESSION["token"])) echo $_SESSION["token"];
        } else {
           include_once("sprinklers.php");
        }
    }
}
function remove_token() {
    global $cache_file;
    $hashs = file($cache_file);
    if (isset($_SESSION['token']) && count($hashs) !== 0) {
        $i = 0;
        foreach ($hashs as $hash){
            $hash = explode(" ",$hash);
            $hash[1] = str_replace("\n", "", $hash[1]);
            if ($hash[1] === $_SESSION['token']) {
                delLineFromFile($cache_file, $i);
                unset($_SESSION['token']);
            }
            $i++;
        }
    }
    unset($hashs);
}
function logout() {
    global $denied, $base_url;
    remove_token();
    $_SESSION = array();
    session_destroy();
    header('Location: '.$base_url);
}
function check_localstorage($token) {
    global $cache_file;
    $starttime = explode(' ', microtime()); 
    $starttime = $starttime[1] + $starttime[0]; 
    $endtime = $starttime - 2592000;
    $hashs = file($cache_file);
    if (count($hashs) !== 0) {
        $i = 0;
        foreach ($hashs as $hash){
            $hash = explode(" ",$hash);
            $hash[2] = str_replace("\n", "", $hash[2]);
            if ($hash[0] <= $endtime) {
                delLineFromFile($cache_file, $i);
                return FALSE;
            }
            if ($token === $hash[1]) {
                $_SESSION['token'] = $token;
                $_SESSION['isauth'] = 1;
                $_SESSION['username'] = $hash[2];
                return TRUE;
            }
            $i++;
        }
    }

    return FALSE;
}
function is_auth() {
    is_ssl();
    if (isset($_SESSION['isauth']) && $_SESSION['isauth'] === 1) { return TRUE; }
    return FALSE;   
}
function is_ssl() {
    if(empty($_SERVER['HTTPS'])) {
        $newurl = 'https://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
        header("Location: ".$newurl);
        exit();
    }
    return TRUE;
}
function checktoken() {
    global $webtitle;

    if (check_localstorage($_POST['token'])) {
        include_once("sprinklers.php");
    } else {
        echo 0;
    }
    exit();
}

function delLineFromFile($fileName, $lineNum){
    $arr = file($fileName);
    $lineToDelete = $lineNum;
    unset($arr["$lineToDelete"]);
    $fp = fopen($fileName, 'w+');
    foreach($arr as $line) { fwrite($fp,$line); }
    fclose($fp);
    return TRUE;
}