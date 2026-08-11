<?php
/**
 * @file db.class.php
 * @description 安装向导专用数据库操作类，封装 mysqli 连接与查询
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(extension_loaded('mysqli')) {
    class DB {
        private static $link;
		public static function connect($db_host,$db_user,$db_pass,$db_name,$db_port){
			self::$link = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);
			return self::$link;
		}
		public static function connect_errno(){
			return mysqli_connect_errno();
		}
		public static function connect_error(){
			return mysqli_connect_error();
		}
		public static function fetch($q){
			return mysqli_fetch_assoc($q);
		}
		public static function get_row($q){
			$result = mysqli_query(self::$link,$q);
			return mysqli_fetch_assoc($result);
		}
		public static function count($q){
			$result = mysqli_query(self::$link,$q);
			$count = mysqli_fetch_array($result);
			return $count[0] ?? 0;
		}
		public static function query($q){
			return mysqli_query(self::$link,$q);
		}
		public static function escape($str){
			return mysqli_real_escape_string(self::$link,$str);
		}
		public static function affected(){
			return mysqli_affected_rows(self::$link);
		}
		public static function errno(){
			return mysqli_errno(self::$link);
		}
		public static function error(){
			return mysqli_error(self::$link);
		}
		public static function close(){
			return mysqli_close(self::$link);
		}
	}
} else {
	class DB {
        private static $link;
		public static function connect($db_host,$db_user,$db_pass,$db_name,$db_port){
			return false;
		}
		public static function connect_errno(){
			return 0;
		}
		public static function connect_error(){
			return 'mysqli扩展未安装，请安装php-mysqli扩展';
		}
		public static function fetch($q){ return false; }
		public static function get_row($q){ return false; }
		public static function count($q){ return 0; }
		public static function query($q){ return false; }
		public static function escape($str){
			// addslashes is insecure for multibyte charsets; require mysqli
			return str_replace(["\\", "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], $str);
		}
		public static function affected(){ return 0; }
		public static function errno(){ return 0; }
		public static function error(){ return 'mysqli扩展未安装'; }
		public static function close(){ return true; }
	}
}
?>
