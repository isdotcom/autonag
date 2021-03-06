#!/usr/bin/php
<?php
require('aws-sdk/aws-autoloader.php');

$shortOpts = 'p:r:i:w:c:ul';
$longOpts = array('profile:', 'region:', 'clusterId:', 'warning:', 'critical:', 'unique', 'list');
$options = getopt($shortOpts, $longOpts);

$required = array('profile', 'region', 'clusters', 'warning', 'critical', 'unique', 'list');
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
		case 'clusterId':
			$opts['clusters'] = explode(',', $value);
			break;
		case 'w':
		case 'warning':
			$opts['warning'] = $value;
			break;
		case 'c':
		case 'critical':
			$opts['critical'] = $value;
			break;
		case 'u':
		case 'unique':
			$opts['unique'] = true;
			break;
		case 'l':
		case 'list':
			$opts['list'] = true;
			break;
	}
}

function usage() {
	echo 'Options:' . PHP_EOL;
	echo ' -p, --profile <string>    Credential profile to use. default: default' . PHP_EOL;
	echo ' -r, --region <string>     Region. Pulled from the credential profile unless provided. example: us-east-1' . PHP_EOL;
	echo ' -i, --clusterId <string>  ECS Cluster to check. Check multiple clusters by separating with comma.' . PHP_EOL;
	echo ' -w, --warning <integer>   The warning threshold for disconnected agents.' . PHP_EOL;
	echo ' -c, --critical <integer>  The critical threshold for disconnected agents.' . PHP_EOL;
	echo ' -u, --unique              Treat clusters uniquely when evaluating warning and critical thresholds.' . PHP_EOL;
	echo ' -l, --list                Use to list available clusters to monitor.' . PHP_EOL;
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

if (!$opts['clusters']) {
	if ($opts['list']) {
		$listClient = new Aws\Ecs\EcsClient($clientOpts);
		$resulta = $listClient->listClusters();
		$resultb = $listClient->describeClusters([
			'clusters' => $resulta['clusterArns']
		]);
		printf('%-40s %-8s' . PHP_EOL, 'clusterName', 'status');
		foreach ($resultb['clusters'] as $cluster) {
			$clusterName = !empty($cluster['clusterName']) ? $cluster['clusterName'] : null;
			$status = !empty($cluster['status']) ? $cluster['status'] : null;
			printf('%-40s %-8s' . PHP_EOL, $clusterName, $status);
		}
		exit(3);
	} else {
		echo 'Must specify a clusterId (-i)!' . PHP_EOL;
		echo 'Replace with (-l) to show which ones are available.' . PHP_EOL;
		usage();
	}
} elseif (!$opts['warning'] && !$opts['critical']) {
	echo 'Must specify both warning (-w), critical (-c), or both values!' . PHP_EOL;
	usage();
}

$client = new Aws\Ecs\EcsClient($clientOpts);

foreach ($opts['clusters'] as $cluster) {
	$status['connected'][$cluster] = 0;
	$status['disconnected'][$cluster] = 0;
	$resulta = $client->listContainerInstances([
		'cluster' => $cluster,
		'status' => 'ACTIVE'
	]);
	$resultb = $client->describeContainerInstances([
		'cluster' => $cluster,
		'containerInstances' => $resulta['containerInstanceArns']
	]);
	foreach ($resultb['containerInstances'] as $instance) {
		if (!$instance['agentConnected']) {
			$status['disconnected'][$cluster]++;
		} else {
			$status['connected'][$cluster]++;
		}
	}
}

foreach ($opts['clusters'] as $cluster) {
	$outputs[] = "{$cluster} (con: {$status['connected'][$cluster]}, dis: {$status['disconnected'][$cluster]})";
}

$output = implode(', ', $outputs);

if ($opts['critical'] && ((!$opts['unique'] && array_sum($status['disconnected']) >= $opts['critical']) || ($opts['unique'] && max($status['disconnected']) >= $opts['critical']))) {
	echo "CRITICAL: {$output}" . PHP_EOL;
	exit(2);
} elseif ($opts['warning'] && ((!$opts['unique'] && array_sum($status['disconnected']) >= $opts['warning']) || ($opts['unique'] && max($status['disconnected']) >= $opts['warning']))) {
	echo "WARNING: {$output}" . PHP_EOL;
	exit(1);
} else {
	echo "OK: {$output}" . PHP_EOL;
	exit(0);
}
?>
