<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

require 'vendor/autoload.php';
require 'RecordClassFinder.php';
require 'ClassInfo.php';
require 'Warning.php';
require 'Colors.php';

use PhpParser\{Node, NodeFinder};
use PhpParser\{Parser, ParserFactory};
use TonyParser\{RecordClassFinder, ClassInfo, Warning};

function usage() {
	global $argv;
	echo "Usage: php $argv[0] <file_to_parse> [verbose] [debug]\n";
}

$target = $argv[1];
if (empty($target)) {
	usage();
	exit;
}

$verbose = false;
if (count($argv) >= 3 && $argv[2]) {
	$verbose = true;
}

$debug = false;
if (count($argv) >= 4 && $argv[3]) {
	$debug = true;
}

$finder = new RecordClassFinder($target);

echo "finding record classes ... \n";
$recordClasses = $finder->findRecordClasses();
echo "  found " . count($recordClasses) . " record classes ... \n";

$tables = [];
foreach ($recordClasses as $name => $classInfo) {
	$possibleTableNames = [];
	$dbNames = $classInfo->getYiiDBNames();
	if (empty($dbNames)) {
		if (empty($classInfo->getChildren())) {
			Warning::addWarning($classInfo->getFileName(), $name, $classInfo, 'cannot find dbName for non-inherited record class');
		}
	}
	$tableNames = $classInfo->getYiiTableNames();
	if (empty($tableNames)) {
		if (empty($classInfo->getChildren())) {
			Warning::addWarning($classInfo->getFileName(), $name, $classInfo, 'cannot find tableName for non-inherited record class');
		}
	} else {
		foreach ($dbNames as $dbName) {
			foreach ($tableNames as $tableName) {
				$possibleTableNames []= $dbName . '.' . $tableName;
			}
		}
		foreach ($possibleTableNames as $possibleTableName) {
			if (!isset($tables[$possibleTableName])) {
				$tables[$possibleTableName] = [];
			}
			$tables[$possibleTableName] []= $classInfo;
		}
	}
	if ($verbose) {
		$info = sprintf("=== %s ===\n  file: %s\n  hierarchy: -> %s\n  table(s): %s\n",
					$name, $classInfo->getFileName(), implode(' -> ', $classInfo->getAncestorNames()), implode(', ', $possibleTableNames));
		echo $info;
	}
}

foreach ($tables as $name => $classInfos) {
	$classNames = [];
	foreach ($classInfos as $classInfo) {
		$classNames []= $classInfo->getClassName();
		if ($debug) {
			var_dump($classInfo);
		}
	}
	$info = sprintf("=== %s ===\n  classes: %s\n",
		$name, implode(',', $classNames));
	echo $info;
}

foreach (Warning::getWarnings() as $warn) {
	echo $warn->getShellExpr($debug);
}

