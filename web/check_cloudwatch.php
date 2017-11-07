#!/usr/bin/php
<?php
require('aws-sdk/aws-autoloader.php');

$shortOpts = 'p:r:i:CLDm:w:c:l';
$longOpts = array('profile:', 'region:', 'instanceId:', 'ec2Metric', 'elbMetric', 'rdsMetric', 'metric:', 'warning:', 'critical:', 'list');
$options = getopt($shortOpts, $longOpts);

$required = array('profile', 'region', 'instance', 'namespace', 'dimension', 'metric', 'warning', 'critical');
$opts = array_fill_keys($required, null);

$list = false;

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
			break;
		case 'L':
		case 'elbMetric':
			$opts['namespace'] = 'AWS/ELB';
			$opts['dimension'] = 'LoadBalancerName';
			break;
		case 'D':
		case 'rdsMetric':
			$opts['namespace'] = 'AWS/RDS';
			$opts['dimension'] = 'DBInstanceIdentifier';
			break;
		case 'm':
		case 'metric':
			$opts['metric'] = $value;
			break;
		case 'w':
		case 'warning':
			$opts['warning'] = $value;
			break;
		case 'c':
		case 'critical':
			$opts['critical'] = $value;
			break;
		case 'l':
		case 'list':
			$list = true;
			break;
	}
}

function usage() {
	echo 'Options:' . PHP_EOL;
	echo ' -p, --profile <value>     Credential profile to use. default: default' . PHP_EOL;
	echo ' -r, --region <value>      Region. Pulled from the credential profile unless provided. example: us-east-1' . PHP_EOL;
	echo ' -i, --instanceId <value>  InstanceID, LoadBalancerName, or DBInstanceIdentifier to check.' . PHP_EOL;
	echo ' -C, --ec2Metric           Use when checking EC2.' . PHP_EOL;
	echo ' -L, --elbMetric           Use when checking ELB.' . PHP_EOL;
	echo ' -D, --rdsMetric           Use when checking RDS.' . PHP_EOL;
	echo ' -m, --metric <value>      The CloudWatch metric to monitor.' . PHP_EOL;
	echo ' -w, --warning <value>     The warning threshold for this metric.' . PHP_EOL;
	echo ' -c, --critical <value>    The critical threshold for this metric.' . PHP_EOL;
	echo ' -l, --list                Use to list available instances or metrics to monitor.' . PHP_EOL;
	exit(3);
}

if ($opts['profile'] && $opts['region']) {
	$clientOpts = array('profile' => $opts['profile'], 'region' => $opts['region'], 'version' => 'latest');
} elseif ($opts['profile']) {
	$clientOpts = array('profile' => $opts['profile'], 'version' => 'latest');
} elseif ($opts['region']) {
	$clientOpts = array('profile' => 'default', 'region' => $opts['region'], 'version' => 'latest');
} else {
	echo 'Must specify profile (-p), region (-r), or both!' . PHP_EOL;
	usage();
}

if (!$opts['instance']) {
	if ($list && $opts['namespace'] && $opts['dimension']) {
		switch ($opts['namespace']) {
			case 'AWS/EC2':
				$listclient = new Aws\Ec2\Ec2Client($clientOpts);
				$result = $listclient->describeInstances();
				printf('%-20s %-60s %-14s %-16s' . PHP_EOL, 'InstanceId', 'InstanceName', 'InstanceState', 'PrivateIpAddress');
				foreach ($result['Reservations'] as $reservation) {
					foreach ($reservation['Instances'] as $instance) {
						foreach ($instance['Tags'] as $tag) {
							if ($tag['Key'] == 'Name') {
								$instanceName = $tag['Value'];
							}
						}
						$instanceId = !empty($instance['InstanceId']) ? $instance['InstanceId'] : null;
						$instanceState = !empty($instance['State']['Name']) ? $instance['State']['Name'] : null;
						$privateIpAddress = !empty($instance['PrivateIpAddress']) ? $instance['PrivateIpAddress'] : null;
						printf('%-20s %-60s %-14s %-16s' . PHP_EOL, $instanceId, $instanceName, $instanceState, $privateIpAddress);
					}
				}
				break;
			case 'AWS/ELB':
				echo 'Not implemented!' . PHP_EOL;
				break;
			case 'AWS/RDS':
				$listclient = new Aws\Rds\RdsClient($clientOpts);
				$result = $listclient->describeDBInstances();
				printf('%-60s %-60s %-16s' . PHP_EOL, 'DBInstanceIdentifier', 'DBClusterIdentifier', 'DBInstanceState');
				foreach ($result['DBInstances'] as $dbinstance) {
					$dBInstanceIdentifier = !empty($dbinstance['DBInstanceIdentifier']) ? $dbinstance['DBInstanceIdentifier'] : null;
					$dBClusterIdentifier = !empty($dbinstance['DBClusterIdentifier']) ? $dbinstance['DBClusterIdentifier'] : null;
					$dBInstanceStatus = !empty($dbinstance['DBInstanceStatus']) ? $dbinstance['DBInstanceStatus'] : null;
					printf('%-60s %-60s %-16s' . PHP_EOL, $dBInstanceIdentifier, $dBClusterIdentifier, $dBInstanceStatus);
				}
				break;
		}
		exit(3);
	} else {
		echo 'Must specify an instanceId (-i)! Add (-C, -L, -D) and (-l) to show which ones are available.' . PHP_EOL;
		usage();
	}
} elseif (!$opts['namespace'] || !$opts['dimension']) {
	echo 'Must specify an instance type (-C, -L, -D)!' . PHP_EOL;
	usage();
} elseif (!$opts['metric']) {
	if ($list) {
		$listclient = new Aws\CloudWatch\CloudWatchClient($clientOpts);
		$dimensions = array('Name' => $opts['dimension'], 'Value' => $opts['instance']);
		$result = $listclient->listMetrics([
			'Dimensions' => [$dimensions],
			'Namespace' => $opts['namespace']
		]);
		foreach ($result['Metrics'] as $metric) {
			echo $metric['MetricName'] . PHP_EOL;
		}
		exit(3);
	} else {
		echo 'Must specify a metric (-m)! Add (-l) to show which ones are available.' . PHP_EOL;
		usage();
	}
} elseif (!$opts['warning'] || !$opts['critical']) {
	echo 'Must specify both warning (-w) and critical (-c) values!' . PHP_EOL;
	usage();
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
