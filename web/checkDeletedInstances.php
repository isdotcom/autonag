#!/usr/bin/php
<?php
class ec2Tracker {
    private $settings = array();
    private $params = array();
    private $trackInstances = array();
    private $foundInstances = array();
    private $nagiosNeedsReload = false;

    public function __construct() {
        require('aws-sdk/aws-autoloader.php');
        $this->settings = parse_ini_file('settings.ini', true);
        if (empty($this->settings['aws']['region'])) {
            echo "'region' is blank in settings.ini - please fix" . PHP_EOL;
            exit(1);
        } elseif (empty($this->settings['aws']['hostType'])) {
            echo "'hostType' is blank in settings.ini - please fix" . PHP_EOL;
            exit(1);
        }
        if (!file_exists($this->settings['paths']['hostConfigDir'])) {
            echo "{$this->settings['paths']['hostConfigDir']} doesn't exist - nothing to do" . PHP_EOL;
            exit(0);
        }
        if (file_exists("{$this->settings['paths']['hostTrackDir']}/{$this->settings['aws']['hostType']}.inf")) {
            if (is_writable("{$this->settings['paths']['hostTrackDir']}/{$this->settings['aws']['hostType']}.inf")) {
                $this->trackInstances = file("{$this->settings['paths']['hostTrackDir']}/{$this->settings['aws']['hostType']}.inf", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            } else {
                echo "{$this->settings['paths']['hostTrackDir']}/{$this->settings['aws']['hostType']}.inf isn't writable - please fix" . PHP_EOL;
                exit(1);
            }
        } else {
            echo "{$this->settings['paths']['hostTrackDir']}/{$this->settings['aws']['hostType']}.inf doesn't exist - nothing to do" . PHP_EOL;
            exit(0);
        }
        if (!file_exists($this->settings['logs']['change'])) {
            if (is_writable(dirname($this->settings['logs']['change']))) {
                touch($this->settings['logs']['change']);
            } else {
                echo dirname($this->settings['logs']['change']) . " isn't writable - please fix" . PHP_EOL;
                exit(1);
            }
        } elseif (!is_writable($this->settings['logs']['change'])) {
                echo "{$this->settings['logs']['change']} isn't writable - please fix" . PHP_EOL;
                exit(1);
        }
    }

    private function getCredentials() {
        $provider = new Aws\Credentials\CredentialProvider;
        $credentials = $provider->defaultProvider();
        return $credentials;
    }

    private function createClient() {
        $credentials = $this->getCredentials();
        $client = new Aws\Ec2\Ec2Client([
            'region' => $this->settings['aws']['region'],
            'credentials' => $credentials,
            'version' => 'latest'
        ]);
        return $client;
    }

    public function getAllInstances() {
        $client = $this->createClient();
        $result = $client->describeInstanceStatus([
//            'IncludeAllInstances' => true
        ]);
        $instances = $result['InstanceStatuses'];
        foreach ($instances as $instance) {
            $this->foundInstances[] = $instance['InstanceId'];
        }
    }

    private function logChange($name, $instanceId) {
        $date = date('r');
        $msg = <<<EOM
-=-=-=-=-=-
{$date}
-=-=-=-=-=-
Host Removed:
    host_name -> {$name}
    notes -> {$instanceId}
EOM;
        file_put_contents($this->settings['logs']['change'], $msg . PHP_EOL, FILE_APPEND);
    }

    private function unmonitorInstance($name, $instanceId) {
        if (file_exists("{$this->settings['paths']['hostConfigDir']}/{$name}.cfg")) {
            rename("{$this->settings['paths']['hostConfigDir']}/{$name}.cfg", "{$this->settings['paths']['hostConfigDir']}/{$name}.cfg.old");
        }
        if (file_exists("{$this->settings['paths']['hostTrackDir']}/{$this->settings['aws']['hostType']}.inf")) {
            exec("sed -i.bak '/{$instanceId}/d' {$this->settings['paths']['hostTrackDir']}/{$this->settings['aws']['hostType']}.inf");
        }
        $this->logChange($name, $instanceId);
    }

    public function findDeletedInstances() {
        foreach ($this->trackInstances as $trackInstance) {
            $instance = explode(':', $trackInstance);
            if (!in_array($instance[1], $this->foundInstances)) {
                $this->unmonitorInstance($instance[0], $instance[1]);
                $this->nagiosNeedsReload = true;
            }
        }
    }

    public function reloadNagios() {
        if ($this->nagiosNeedsReload) {
            exec('service nagios3 reload');
        }
    }
}

$tracker = new ec2Tracker();
$tracker->getAllInstances();
$tracker->findDeletedInstances();
$tracker->reloadNagios();
?>
