<?php
class HostConfigBuilder {
	// List of required arguments
	private $required = array('name', 'ip', 'type', 'version', 'instanceId');

	// Paths
	public $templateURL = 'https://raw.githubusercontent.com/isdotcom/nagios-auto/master/templates/%VERSION%/ecs-pod/nagios-host.template';
	public $hostConfigDir = '/etc/nagios3/conf.d/hosts';
	public $hostTrackDir = '/etc/nagios3';

	public function __construct() {
		// Make sure we have the required arguments (can come from _GET, _POST, or sometimes _COOKIES)
		foreach ($this->required as $arg) {
			if (isset($_REQUEST[$arg])) {
				$this->$arg = $_REQUEST[$arg];
			} else {
				// Populate all of the required args with **MISSING**
				$required = array_fill_keys($this->required, '**MISSING**');
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
	public function downloadTemplate() {
		$merge = array('%VERSION%' => $this->version);
		$templateURL = strtr($this->templateURL, $merge);
		$result = @file_get_contents($templateURL);
		if ($result != false) {
			$this->template = $result;
		} else {
			$this->badRequest(array('error' => 'Unable to download template'));
		}
	}

	// Merge our args with the template to create a useable config
	public function mergeTemplate() {
		$this->downloadTemplate();
		$merge = array('%HOSTNAME%' => $this->name, '%IP%' => $this->ip, '%NOTE%' => $this->instanceId, '%HOSTGROUP%' => $this->type);
		$this->mergedTemplate = strtr($this->template, $merge);
	}

	// Write the config to hostConfigDir
	// Save the name:instanceId pair in hostTrackDir so we can track this later
	public function writeConfig() {
		echo $this->mergedTemplate;
		file_put_contents("{$this->hostConfigDir}/{$this->name}.cfg", $this->mergedTemplate);
		file_put_contents("{$this->hostTrackDir}/{$this->type}.inf", "{$this->name}:{$this->instanceId}" . PHP_EOL, FILE_APPEND);
	}

	// Let Nagios know there's a new config
	public function reloadNagios() {
		exec('service nagios3 reload');
	}
}

$hostConfig = new HostConfigBuilder();
$hostConfig->mergeTemplate();
$hostConfig->writeConfig();
$hostConfig->reloadNagios();
?>
