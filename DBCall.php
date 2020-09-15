<?php declare(strict_types=1);
/** vim: set number noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

class DBCall
{
	public $db = '';
	public $table = '';
	public $sql =  '';

	public $source = '';
	public $line = '';
	public $class = '';
	public $method = '';

	public function __construct($attributes)
	{
		foreach ($attributes as $attr => $val) {
			if (isset($this->$attr)) {
				$this->$attr = $val;
			}
		}
	}

	public static $dbCalls = [];

	public static function registerDBCall($attributes)
	{
		self::$dbCalls []= new DBCall($attributes);
	}

	public static function registerDBCallsFromNode($node, $attributes)
	{
		$attributes['source'] = $node->getAttribute('file');
		$attributes['line'] = $node->getStartLine();
		$attributes['class'] = VarStackVisitor::getNodeClass($node);
		$attributes['method'] = VarStackVisitor::getNodeMethod($node);
		self::$dbCalls []= new DBCall($attributes);
	}

}

