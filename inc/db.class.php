<?php
/**
 * @file db.class.php
 * @description MySQL/MySQLi/SQLite 三合一数据库操作类（含预处理语句支持）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined('IN_CRONLITE'))exit();

$nomysqli=false;

if(defined('SQLITE')==true){
	class DB {
		public $link = null;

		function __construct($db_file){
			global $siteurl;
		$this->link = new PDO('sqlite:'.ROOT.'includes/sqlite/'.$db_file.'.db');
		if (!$this->link) die('Connection Sqlite failed.\n');
		return true;
        }

		function fetch($q){
			return $q->fetch();
		}
		function get_row($q){
			$sth = $this->link->query($q);
			if($sth === false) return false;
			return $sth->fetch();
		}
		function count($q){
			$sth = $this->link->query($q);
			if($sth === false) return 0;
			return $sth->fetchColumn();
		}
		function query($q){
			return $this->result=$this->link->query($q);
		}
		function affected(){
			return $this->result->rowCount();
		}
		function error(){
			$error = $this->link->errorInfo();
			return '['.($error[1] ?? 0).'] '.($error[2] ?? '');
		}
		// —— 预处理语句方法（SQLite/PDO 版） ——
		function prepared(string $sql, string $types = '', array $params = []){
			$sth = $this->link->prepare($sql);
			if($sth === false) return false;
			$sth->execute($params);
			return $sth;
		}
		function get_row_prepared(string $sql, string $types = '', array $params = []){
			$sth = $this->prepared($sql, $types, $params);
			if($sth === false) return false;
			return $sth->fetch(PDO::FETCH_ASSOC);
		}
		function count_prepared(string $sql, string $types = '', array $params = []){
			$sth = $this->prepared($sql, $types, $params);
			if($sth === false) return 0;
			return (int)$sth->fetchColumn();
		}
		function query_prepared(string $sql, string $types = '', array $params = []){
			$sth = $this->prepared($sql, $types, $params);
			if($sth === false) return false;
			return $sth->rowCount();
		}
		function insert_prepared(string $sql, string $types = '', array $params = []){
			$sth = $this->prepared($sql, $types, $params);
			if($sth === false) return false;
			return (int)$this->link->lastInsertId();
		}
		function fetch_all_prepared(string $sql, string $types = '', array $params = []){
			$sth = $this->prepared($sql, $types, $params);
			if($sth === false) return false;
			return $sth; // PDOStatement 可直接 fetch 遍历
		}
		// —— 事务支持（PDO 版） ——
		function begin_transaction(){
			return (bool)$this->link->beginTransaction();
		}
		function commit(){
			return (bool)$this->link->commit();
		}
		function rollback(){
			return (bool)$this->link->rollBack();
		}
		function escape($str){
			return $str;
		}
	}
}
elseif(extension_loaded('mysqli') && $nomysqli==false) {
    class DB {
        public $link = null;

        function __construct($db_host,$db_user,$db_pass,$db_name,$db_port){
            
            $this->link = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);

            // 连接失败：详情记录到错误日志，页面仅显示通用提示（防信息泄露）
            if (!$this->link) {
                error_log('DB connect failed (' . mysqli_connect_errno() . '): ' . mysqli_connect_error());
                die('数据库连接失败，请检查配置或联系管理员。');
            }
            
            mysqli_query($this->link,"set sql_mode = ''");
            //字符转换，读库
            mysqli_query($this->link,"set character set 'utf8mb4'");
            //写库
            mysqli_query($this->link,"set names 'utf8mb4'"); 
	return true;
	}
		function fetch($q){
			return mysqli_fetch_assoc($q);
		}
		function get_row($q){
			$result = mysqli_query($this->link,$q);
			if($result === false) return false;
			return mysqli_fetch_assoc($result);
		}
		function count($q){
			$result = mysqli_query($this->link,$q);
			if($result === false) return 0;
			$count = mysqli_fetch_array($result);
			return $count[0] ?? 0;
		}
		function query($q){
			return mysqli_query($this->link,$q);
		}
		function escape($str){
			return mysqli_real_escape_string($this->link,$str);
		}
		function insert($q){
			if(mysqli_query($this->link,$q))
				return mysqli_insert_id($this->link); 
			return false;
		}
		function affected(){
			return mysqli_affected_rows($this->link);
		}
		/**
		 * 批量插入数组（已重构为预处理语句）
		 * 表名来自内部代码，非用户输入，可安全拼入 SQL
		 */
		function insert_array(string $table, array $array){
			$columns = array_keys($array);
			$placeholders = array_fill(0, count($array), '?');
			$types = '';
			$params = [];
			foreach($array as $val){
				if(is_int($val)) $types .= 'i';
				elseif(is_float($val)) $types .= 'd';
				else $types .= 's';
				$params[] = $val;
			}
			$sql = "INSERT INTO `$table` (`" . implode('`,`', $columns) . "`) VALUES (" . implode(',', $placeholders) . ")";
			$stmt = $this->prepared($sql, $types, $params);
			if($stmt === false) return false;
			$insertId = mysqli_insert_id($this->link);
			mysqli_stmt_close($stmt);
			return $insertId;
		}
		// ============================================================
		// 预处理语句方法（mysqli 版）—— 符合 § 8.3.4 规范
		// 用法：$DB->get_row_prepared("SELECT * FROM users WHERE id=?", 'i', [$id])
		// ============================================================

		/**
		 * 预处理执行 SQL（核心方法）
		 * @param string $sql 带 ? 占位符的 SQL
		 * @param string $types 参数类型（i=int,d=double,s=string,b=blob）
		 * @param array $params 参数值数组
		 * @return \mysqli_stmt|false
		 */
		function prepared(string $sql, string $types = '', array $params = []){
			$stmt = mysqli_prepare($this->link, $sql);
			if($stmt === false){
				error_log('DB prepare failed: ' . $this->error() . ' SQL: ' . $sql);
				return false;
			}
			if(!empty($params)){
				mysqli_stmt_bind_param($stmt, $types, ...$params);
			}
			if(!mysqli_stmt_execute($stmt)){
				error_log('DB execute failed: ' . $this->error() . ' SQL: ' . $sql);
				mysqli_stmt_close($stmt);
				return false;
			}
			return $stmt;
		}

		/**
		 * 预处理查询单行
		 * @return array|false 关联数组或 false
		 */
		function get_row_prepared(string $sql, string $types = '', array $params = []){
			$stmt = $this->prepared($sql, $types, $params);
			if($stmt === false) return false;
			$result = mysqli_stmt_get_result($stmt);
			if($result === false){
				mysqli_stmt_close($stmt);
				return false;
			}
			$row = mysqli_fetch_assoc($result);
			mysqli_stmt_close($stmt);
			return $row;
		}

		/**
		 * 预处理查询计数
		 * @return int
		 */
		function count_prepared(string $sql, string $types = '', array $params = []){
			$stmt = $this->prepared($sql, $types, $params);
			if($stmt === false) return 0;
			$result = mysqli_stmt_get_result($stmt);
			if($result === false){
				mysqli_stmt_close($stmt);
				return 0;
			}
			$count = mysqli_fetch_array($result);
			mysqli_stmt_close($stmt);
			return $count[0] ?? 0;
		}

		/**
		 * 预处理执行写操作（INSERT/UPDATE/DELETE），返回影响行数
		 * @return int|false
		 */
		function query_prepared(string $sql, string $types = '', array $params = []){
			$stmt = $this->prepared($sql, $types, $params);
			if($stmt === false) return false;
			$affected = mysqli_stmt_affected_rows($stmt);
			mysqli_stmt_close($stmt);
			return $affected;
		}

		/**
		 * 预处理插入，返回插入 ID
		 * @return int|false
		 */
		function insert_prepared(string $sql, string $types = '', array $params = []){
			$stmt = $this->prepared($sql, $types, $params);
			if($stmt === false) return false;
			$insertId = mysqli_insert_id($this->link);
			mysqli_stmt_close($stmt);
			return $insertId;
		}

		/**
		 * 预处理查询多行结果集，返回 mysqli_result
		 * 调用方需自行 while($row=$DB->fetch($rs)) 遍历，遍历后无需手动释放
		 * @return \mysqli_result|false
		 */
		function fetch_all_prepared(string $sql, string $types = '', array $params = []){
			$stmt = $this->prepared($sql, $types, $params);
			if($stmt === false) return false;
			$result = mysqli_stmt_get_result($stmt);
			mysqli_stmt_close($stmt);
			if($result === false) return false;
			return $result;
		}
		// ============================================================
		// 事务支持（mysqli 版）—— 用于保证「先删后插」等复合操作的原子性
		// ============================================================
		function begin_transaction(){
			return (bool)mysqli_begin_transaction($this->link);
		}
		function commit(){
			return (bool)mysqli_commit($this->link);
		}
		function rollback(){
			return (bool)mysqli_rollback($this->link);
		}
		function error(){
			$error = mysqli_error($this->link);
			$errno = mysqli_errno($this->link);
			return '['.$errno.'] '.$error;
		}
		function close(){
			$q = mysqli_close($this->link);
			return $q;
		}
	}
} else { // mysqli扩展未安装时的提示
	class DB {
		public $link = null;

		function __construct($db_host,$db_user,$db_pass,$db_name,$db_port){
			die('服务器未安装mysqli扩展，请先安装php-mysqli扩展后再使用本系统。');
		}
		function fetch($q){ return false; }
		function get_row($q){ return false; }
		function count($q){ return 0; }
		function query($q){ return false; }
		function escape($str){
			return str_replace(["\\", "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], $str);
		}
		function affected(){ return 0; }
		function insert($q){ return false; }
		function insert_array($table,$array){ return false; }
		function prepared(string $sql, string $types = '', array $params = []): bool { return false; }
		function get_row_prepared(string $sql, string $types = '', array $params = []) { return false; }
		function count_prepared(string $sql, string $types = '', array $params = []): int { return 0; }
		function query_prepared(string $sql, string $types = '', array $params = []) { return false; }
		function insert_prepared(string $sql, string $types = '', array $params = []) { return false; }
		function fetch_all_prepared(string $sql, string $types = '', array $params = []) { return false; }
		function begin_transaction(){ return false; }
		function commit(){ return false; }
		function rollback(){ return false; }
		function error(){ return 'mysqli扩展未安装'; }
		function close(){ return true; }
	}
}
