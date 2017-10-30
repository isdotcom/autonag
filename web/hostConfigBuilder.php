<?php
class hostConfigBuilder {
    private $settings = array();
    private $params = array();

    public function __construct() {
        $this->settings = parse_ini_file('settings.ini', true);
        if (!file_exists($this->settings['paths']['hostConfigDir'])) {
            if (!is_writable(dirname($this->settings['paths']['hostConfigDir']))) {
                $this->badRequest(array('error' => 'Not writable', 'path' => dirname($this->settings['paths']['hostConfigDir'])));
            } else {
                mkdir($this->settings['paths']['hostConfigDir'], 0755, true);
            }
        } elseif (!is_writable($this->settings['paths']['hostConfigDir'])) {
            $this->badRequest(array('error' => 'Not writable', 'path' => $this->settings['paths']['hostConfigDir']));
        }
        if (!file_exists($this->settings['paths']['hostTrackDir'])) {
            if (!is_writable(dirname($this->settings['paths']['hostTrackDir']))) {
                $this->badRequest(array('error' => 'Not writable', 'path' => dirname($this->settings['paths']['hostTrackDir'])));
            } else {
                mkdir($this->settings['paths']['hostTrackDir'], 0755, true);
            }
        } elseif (!is_writable($this->settings['paths']['hostTrackDir'])) {
            $this->badRequest(array('error' => 'Not writable', 'path' => $this->settings['paths']['hostTrackDir']));
        }
        if (!file_exists($this->settings['logs']['change'])) {
            if (!is_writable(dirname($this->settings['logs']['change']))) {
                $this->badRequest(array('error' => 'Not writable', 'path' => dirname($this->settings['logs']['change'])));
            } else {
                touch($this->settings['logs']['change']);
            }
        } elseif (!is_writable($this->settings['logs']['change'])) {
            $this->badRequest(array('error' => 'Not writable', 'path' => $this->settings['logs']['change']));
        }
        // Make sure we have the required arguments (can come from _GET, _POST, or sometimes _COOKIES)
        foreach ($this->settings['args']['required'] as $arg) {
            if (isset($_REQUEST[$arg])) {
                $this->params[$arg] = $_REQUEST[$arg];
            } else {
                // Populate all of the required args with **MISSING**
                $required = array_fill_keys($this->settings['args']['required'], '**MISSING**');
                // Overwrite all of the **MISSING** args with values we actually have
                // This lets the user know what we receive and what we didn't
                $this->badRequest(array('error' => 'Missing argument(s)', 'args' => array_merge($required, $_REQUEST)));
            }
        }
    }

    // This handles notifying the user if something went wrong
    private function badRequest($args) {
        header('HTTP/1.0 400 Bad Request');
        print_r($args);
        exit;
    }

    // Pull the template from templateURL (github?) if we can
    private function downloadTemplate() {
        $merge = array('%VERSION%' => $this->params['version'], '%TYPE%' => $this->params['type']);
        $templateUrl = strtr($this->settings['paths']['templateUrl'], $merge);
        $result = @file_get_contents($templateUrl);
        if ($result != false) {
            return $result;
        } else {
            $this->badRequest(array('error' => 'Unable to download template'));
        }
    }

    // Merge our args with the template to create a useable config
    private function mergeTemplate() {
        $template = $this->downloadTemplate();
        $merge = array('%HOSTNAME%' => $this->params['name'], '%IP%' => $this->params['ip'], '%NOTE%' => $this->params['instanceId'], '%HOSTGROUP%' => $this->params['type']);
        return strtr($template, $merge);
    }

    private function logChange() {
        $date = date('r');
        $msg = <<<EOM
-=-=-=-=-=-
{$date}
-=-=-=-=-=-
Host Added:
    version -> {$this->params['version']}
    host_name -> {$this->params['name']}
    address -> {$this->params['ip']}
    hostgroups -> {$this->params['name']}
    notes -> {$this->params['instanceId']}
EOM;
        file_put_contents($this->settings['logs']['change'], $msg . PHP_EOL, FILE_APPEND);
    }

    // Write the config to hostConfigDir
    // Save the name:instanceId pair in hostTrackDir so we can track this later
    public function writeConfig() {
        $mergedTemplate = $this->mergeTemplate();
        file_put_contents("{$this->settings['paths']['hostConfigDir']}/{$this->params['name']}.cfg", $mergedTemplate);
        file_put_contents("{$this->settings['paths']['hostTrackDir']}/{$this->params['type']}.inf", "{$this->params['name']}:{$this->params['instanceId']}" . PHP_EOL, FILE_APPEND);
        $this->logChange();
    }

    // Let Nagios know there's a new config
    public function reloadNagios() {
        exec('service nagios3 reload');
        echo "The host is now being monitored" . PHP_EOL;
    }
}

$hostConfig = new hostConfigBuilder();
$hostConfig->writeConfig();
$hostConfig->reloadNagios();
?>
