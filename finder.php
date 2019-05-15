<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

require 'vendor/autoload.php';
require 'RecordClassFinder.php';
require 'ClassInfo.php';
require 'Warning.php';

use PhpParser\{Node, NodeFinder};
use PhpParser\{Parser, ParserFactory};
use TonyParser\{RecordClassFinder, ClassInfo, Warning};

function usage() {
	global $argv;
	echo "Usage: php $argv[0] <file_to_parse> [verbose]\n";
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

$finder = new RecordClassFinder($target);

echo "finding record classes ... \n";
$recordClasses = $finder->findRecordClasses();
echo "  found " . count($recordClasses) . " record classes ... \n";

foreach ($recordClasses as $name => $classInfo) {
	echo $name . "\n";
	echo "  " . $classInfo->getFileName() . "\n";
	echo "  " . implode(' -> ', $classInfo->getAncestors()) . "\n";
	$tableName = $classInfo->getYiiTableName();
	if (empty($tableName)) {
		if (empty($classInfo->getChildren())) {
			$finder->addWarning($classInfo->getFileName(), $name, $classInfo, 'cannot find tableName for non-inherited record class');
		}
	} else {
		echo "  tableName: $tableName\n";
	}
}

foreach ($finder->getWarnings() as $warn) {
	echo $warn->getShellExpr($verbose);
}

