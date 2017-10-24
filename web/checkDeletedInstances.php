#!/usr/bin/php
<?php
class ec2Tracker {
    private $settings = array();
    private $params = array();
    private $trackInstances = array();
    private $foundInstances = array();
    private $nagiosNeedsReload = false;

    public function __construct($type) {
        require('aws-sdk/aws-autoloader.php');
        $this->settings = parse_ini_file('settings.ini', true);
        if (!is_writable($this->settings['paths']['hostConfigDir'])) {
            echo "{$this->settings['paths']['hostConfigDir']} is not writable." . PHP_EOL;
            exit(1);
        } elseif (!is_writable($this->settings['paths']['hostTrackDir'])) {
            echo "{$this->settings['paths']['hostTrackDir']} is not writable." . PHP_EOL;
            exit(1);
        } elseif (!is_writable($this->settings['logs']['change'])) {
            echo "{$this->settings['logs']['change']} is not writable." . PHP_EOL;
            exit(1);
        }
        $this->params['type'] = $type;
        $this->trackInstances = file("{$this->settings['paths']['hostTrackDir']}/{$this->params['type']}.inf", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }

    private function getCredentials() {
        $provider = new Aws\Credentials\CredentialProvider;
	$credentials = $provider->defaultProvider();
        return $credentials;
    }

    private function createClient($region) {
	$credentials = $this->getCredentials();
        $client = new Aws\Ec2\Ec2Client([
            'region' => $region,
            'credentials' => $credentials,
            'version' => 'latest'
        ]);
        return $client;
    }

    public function getAllInstances($region) {
        $client = $this->createClient($region);
        $result = $client->describeInstanceStatus([
            'IncludeAllInstances' => true
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
        rename("{$this->settings['paths']['hostConfigDir']}/{$name}.cfg", "{$this->settings['paths']['hostConfigDir']}/{$name}.cfg.old");
        exec("sed -i.bak '/{$instanceId}/d' {$this->settings['paths']['hostTrackDir']}/{$this->params['type']}.inf");
        $this->logChange($name, $instanceId);
        $this->nagiosNeedsReload = true;
    }

    public function findDeletedInstances() {
        foreach ($this->trackInstances as $trackInstance) {
            $instance = explode(':', $trackInstance);
            if (!in_array($instance[1], $this->foundInstances)) {
                $this->unmonitorInstance($instance[0], $instance[1]);
            }
        }
    }

    public function reloadNagios() {
        if ($this->nagiosNeedsReload) {
            exec('service nagios3 reload');
        }
    }
}

$tracker = new ec2Tracker('ecs-pod');
$tracker->getAllInstances('us-west-2');
$tracker->findDeletedInstances();
$tracker->reloadNagios();
?>
