<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

class Logger
{

	public static function info($message)
	{
		printf("[%s] <%d> INFO: %s\n", date('Y-m-d H:i:s'), memory_get_usage(), $message);
		flush();
	}

}
