#!/usr/bin/php
<?php
require('aws-sdk/aws-autoloader.php');

$shortOpts = 'p:r:i:C:L:D:S:w:c:';
$longOpts = array('profile:', 'region:', 'instanceId:', 'ec2Metric:', 'elbMetric:', 'rdsMetric:', 'stat:', 'warning:', 'critical:');
$options = getopt($shortOpts, $longOpts);

$required = array('profile', 'region', 'namespace', 'dimension', 'instance', 'metric', 'stat', 'warning', 'critical');
$opts = array_fill_keys($required, null);

foreach ($options as $key => $value) {
	switch ($key) {
		case 'p':
		case 'profile':
			$opts['profile'] = $value;
			break;
		case 'r':
		case 'region':
			$opts['region'] = $value;
			break;
		case 'i':
		case 'instanceId':
			$opts['instance'] = $value;
			break;
		case 'C':
		case 'ec2Metric':
			$opts['namespace'] = 'AWS/EC2';
			$opts['dimension'] = 'InstanceId';
			$opts['metric'] = $value;
			break;
		case 'L':
		case 'elbMetric':
			$opts['namespace'] = 'AWS/ELB';
			$opts['dimension'] = 'LoadBalancerName';
			$opts['metric'] = $value;
			break;
		case 'D':
		case 'rdsMetric':
			$opts['namespace'] = 'AWS/RDS';
			$opts['dimension'] = 'DBInstanceIdentifier';
			$opts['metric'] = $value;
			break;
		case 'S':
		case 'stat':
			// Not implemented
			$opts['stat'] = $value;
			break;
		case 'w':
		case 'warning':
			$opts['warning'] = $value;
			break;
		case 'c':
		case 'critical':
			$opts['critical'] = $value;
			break;
	}
}

if ($opts['profile'] && $opts['region']) {
	$clientOpts = array('profile' => $opts['profile'], 'region' => $opts['region'], 'version' => 'latest');
} elseif ($opts['profile']) {
	$clientOpts = array('profile' => $opts['profile'], 'version' => 'latest');
} elseif ($opts['region']) {
	$clientOpts = array('profile' => 'default', 'region' => $opts['region'], 'version' => 'latest');
} else {
	echo 'Must specify profile, region, or both!' . PHP_EOL;
	exit(3);
}

if (!$opts['instance']) {
	echo 'Must specify an instanceId!' . PHP_EOL;
	exit(3);
} elseif (!$opts['metric']) {
	echo 'Must specify a metric!' . PHP_EOL;
	exit(3);
} elseif (!$opts['warning'] || !$opts['critical']) {
	echo 'Must specify both warning and critical values!' . PHP_EOL;
	exit(3);
}

$client = new Aws\CloudWatch\CloudWatchClient($clientOpts);
$dimensions = array('Name' => $opts['dimension'], 'Value' => $opts['instance']);
$statistics = array('SampleCount', 'Average', 'Sum', 'Minimum', 'Maximum');
$result = $client->getMetricStatistics([
	'Dimensions' => [$dimensions],
	'EndTime' => time(),
	'MetricName' => $opts['metric'],
	'Namespace' => $opts['namespace'],
	'Period' => 120,
	'Statistics' => $statistics,
	'StartTime' => time() - 600
]);
$datapoint = $result['Datapoints'][0];
$metric = $datapoint['Average'] * 100;

if ($metric > $opts['critical']) {
	echo "CRITICAL: Average {$opts['metric']} is ${metric}%" . PHP_EOL;
	exit(2);
} elseif ($metric > $opts['warning']) {
	echo "WARNING: Average {$opts['metric']} is ${metric}%" . PHP_EOL;
	exit(1);
} else {
	echo "OK: Average {$opts['metric']} is ${metric}%" . PHP_EOL;
	exit(0);
}
?>
