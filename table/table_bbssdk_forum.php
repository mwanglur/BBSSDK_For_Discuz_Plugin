<?php
if(!defined('IN_DISCUZ')) {
	exit('Access Denied');
}

class table_bbssdk_forum extends table_forum_post
{
	public function __construct()
	{
		$this->_table = "forum_post";
		$this->_pk = "pid";

		parent::__construct();
	}

	public function count_by_tid($tid)
	{
		return $tid ?  DB::result_first("SELECT COUNT(*) FROM %t WHERE tid=%d", array($this->_table, $tid)) : 0;
	}

	public function fetch_by_tid($tid)
	{
		$data = array();
		if(!empty($tid)) {
			$data = DB::fetch_first('SELECT * FROM '.DB::table($this->_table).' WHERE '.DB::field('tid', $tid));
		}
		return $data;
	}

	public function fetch_threadpost_by_tid($tids) {
		return DB::fetch_all('SELECT * FROM '.DB::table(self::get_tablename('tid:'.$tid)).' WHERE tid in ('. ( is_array($tids) ? join(',',$tids) : $tids ) .') AND first=1');
	}
}