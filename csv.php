<?php

/**
 * 类似 python 的 csv.DictReader (https://docs.python.org/3/library/csv.html#csv.DictReader) 工具,
 * 提供对 csv 文件的解析服务.
 *
 * @param $source, str, 文件名或内容(如 "name,age\nflyer,26\ncompany,10\n")
 * @param $delimiter, str, 每行内容中的分隔符, 如 ","、"\t"
 * @param $headers, array, 补充的 csv 头部内容, 如 ['name', 'age'], 若 $source 第一行为头部信息, $headers 设为 []
 * @param $type, str, 表征 $source 类型, 取值为 'file' 或 'string'
 * @return generator, 每次遍历时得到一个关联数组, 关联 $headers 和每行数据
 *
 * e.g.:
 * 1)
 * $fname = 'campaign.csv';
 *
 * foreach (csvDictReader($fname) as $row) {
 *     var_dump($row['user_id']);  // 'user_id' 是 $fname 表头的一个字段
 * }
 *
 * 2)
 * $contents = "name,age\nflyer,26\ncompany,10";
 * foreach (csvDictReader($contents, ",", [], 'string') as $row) {
 *     var_dump($row['name']);
 * }
 *
 * Note:
 * + 注意 $delimiter 参数最好是通过 "" 包裹的字符串
 * */
function csvDictReader($source, $delimiter=',', $headers=[], $type='file') {
	if ($type == 'file') {
		$fp = new SplFileObject($source);
	} else {
		$fp = new SplFileObject('php://temp', 'w+');
		$fp->fwrite($source);
		$fp->rewind();
	}
	$fp->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

	if (empty($headers)) {
		// 没有显式指定 headers
		$headers = $fp->fgetcsv($delimiter);
	}

	$numHeaders = count($headers);
	while (!$fp->eof() && ($items=$fp->fgetcsv($delimiter)) != [null] && $items != null && count($items) === $numHeaders) {
		$dict = array_combine($headers, $items);

		yield $dict;
	}

	$fp = null;  // 关闭文件
}
