<?php
// Ragnarok Packet Analyzer Class
// Yommy 2011 <3

class parser {
	// Packet being parsed
	public $stream;
	public $packet, $packet_id, $packet_length, $packet_pointer, $packet_desc;
	public $packet_num, $packet_dir, $prev_packet, $prev_packet_dir;
	
	// Static Data
	public $unit    = array();      // array to store seen units for later use
	public $job     = array();      // jobs , mobs , mercs,  npc ids
	public $item    = array();      // items
	public $vars    = array();      // vars
	public $skill   = array();      // skills
	public $efst    = array();      // status
	
	// Packet Info
	public $p_lens  = array();      // packet length array
	public $p_funcs = array();      // packet analyzer functions
	
	
	// 
	public $aid = null;
	public $aid_packet = false;
	public $nl = "|     |     |      |                                                    |";
	public $br = "|.....|.....|......|....................................................|...............................\n";

	function __construct() {
		// Load Static Data
		$this->load_data("enum/jobtype.txt",	"job");
		$this->load_data("enum/item.txt",		"item");
		$this->load_data("enum/var.txt",		"vars");
		$this->load_data("enum/skill.txt",		"skill");
		$this->load_data("enum/efst.txt",		"efst");
		// Load Packet Info
		$this->load_data("packet/plen.txt",		"p_lens");
		$this->load_data("packet/func.txt",		"p_funcs");
		//print_r($this->vars);
	}

	function end_packet() {
		if($this->packet_pointer < $this->packet_length){
			$extra_bytes = $this->packet_length - $this->packet_pointer;
			echo "$this->nl $extra_bytes bytes not analyzed\n";
		}
	}

	function byte($pointer = null) {
		if($pointer) {
			$data = $this->unpack2("C", substr($this->packet, $pointer, 1));
		} else {
			$data = $this->unpack2("C", substr($this->packet, $this->packet_pointer, 1));
			$this->packet_pointer += 1;
		}
		return $data;
	}

	function word($pointer = null) {
		if($pointer) {
			$data = $this->unpack2("S", substr($this->packet, $pointer, 2));
		} else {
			$data = $this->unpack2("S", substr($this->packet, $this->packet_pointer, 2));
			$this->packet_pointer += 2;
		}
		return $data;
	}

	function long($pointer = null) {
		if($pointer) {
			$data = $this->unpack2("L", substr($this->packet, $pointer, 4));
		} else {
			$data = $this->unpack2("L", substr($this->packet, $this->packet_pointer, 4));
			$this->packet_pointer += 4;
		}
		return $data;
	}

	function string($len, $pointer = null) {
		if($pointer) {
			$data1 = substr($this->packet, $pointer, $len);
		} else {
			$data1 = substr($this->packet, $this->packet_pointer, $len);
			$this->packet_pointer += $len;
		}
		$data2 = explode("\0", $data1);  // do not return after null char 00 :)
		$data3 = trim($data2[0]);
		return $data3;
	}

	function ip($pointer = null) {
		if($pointer) {
			$data = long2ip($this->unpack2("L", substr($this->packet, $pointer, 4)));
		} else {
			$data = long2ip($this->unpack2("L", substr($this->packet, $this->packet_pointer, 4)));
			$this->packet_pointer += 4;
		}
		return $data;
	}
	
	function unixtime($pointer = null) {
		if($pointer) {
			$data = $this->unpack2("L", substr($this->packet, $pointer, 4));
		} else {
			$data = $this->unpack2("L", substr($this->packet, $this->packet_pointer, 4));
			$this->packet_pointer += 4;
		}
		$data = date(DATE_ATOM, $data);
		return $data;
	}

	function xyxy($pointer = null) {
		if($pointer) {
			$bin = substr($this->packet, $pointer, 6);
		} else {
			$bin = substr($this->packet, $this->packet_pointer, 6);
			$this->packet_pointer += 6;
		}
		$X = $this->get_val($bin, 0);
		$Y = $this->get_val($bin, 1);
		$X2 = $this->get_val($bin, 2);
		$Y2 = $this->get_val($bin, 3);
		$temp = "$X,$Y to $X2,$Y2";
		return $temp;
	}

	function xy($pointer = null) {
		if($pointer) {
			$bin = substr($this->packet, $pointer, 3);
		} else {
			$bin = substr($this->packet, $this->packet_pointer, 3);
			$this->packet_pointer += 3;
		}
		$X = $this->get_val($bin, 0);
		$Y = $this->get_val($bin, 1);
		$temp = "$X,$Y";
		return $temp;
	}
	
	function get_val(&$bin, $startbyte) {
		$startbit = $startbyte * 2;
		return ((((ord($bin{$startbyte}) << $startbit) & 0xFF) >> $startbit) << (2 + $startbit)) | (ord($bin{$startbyte + 1}) >> (6 - $startbit));
		// mindfuck O_O
	}
	
	function load_data($filename, $arr) {
		$starttime = microtime(true);
		$filename = "./data/$filename";
		$txtfile = fopen($filename, 'r') or exit("Unable to open $filename");
		while (!feof($txtfile)) {
			if (preg_match('/(.*),(.*)/', fgets($txtfile), $regs)) {
				$this->{$arr}[$regs[1]] = trim($regs[2]);
			}
		}
		fclose($txtfile);
		$totaltime = microtime(true) - $starttime;
		echo str_pad("load_data($filename)", 34, " ")." Time: " . round($totaltime, 3) . "s\n";
	}
	
	function unpack2($format, $string) {
		// custom unpack();
		// returns value if only a single element in array
		$temp = unpack($format, $string);
		if(count($temp) == 1){
			return $temp[1];
		} else {
			return $temp;
		}
	}
	
	function parse_stream(){
		// Check for a partial packet from previous stream
		if($this->prev_packet && $this->packet_dir == $this->prev_packet_dir){
			//echo "previous partial packet pre-concatenated\n";
			$this->stream = $this->prev_packet . $this->stream;
			$this->prev_packet = false;
			$this->prev_packet_dir = false;
		}
		$this->packet_num = str_pad(++$this->packet_num, 3, "0", STR_PAD_LEFT);
		while(strlen($this->stream)) {
			$this->packet_id = str_pad(strtoupper(dechex($this->unpack2("S", $this->stream))),4,"0",STR_PAD_LEFT);
			// next packet is Server -> Client
			if($this->packet_id == "5252") { // RR
				$this->packet_dir = "R";
				//echo "direction set to R\n";
				$this->stream = substr($this->stream, 2);
				continue;
			}
			// next packet is Client -> Server
			if($this->packet_id == "5353") { // SS
				$this->packet_dir = "S";
				//echo "direction set to S\n";
				$this->stream = substr($this->stream, 2);
				continue;
			}
			// catch this stupid Account ID aegis sends
			if (!$this->aid_packet && $this->aid && $this->unpack2("L", $this->stream) == $this->aid) {
				//if (function_exists('GID'))
				//	GID($this);
				echo "| $this->packet_num |  $this->packet_dir  |     | Account_ID\n";
				$this->aid_packet = true;
				$this->stream = substr($this->stream, 4);
				continue;
			}
			// Store Account_ID for checking
			if(!$this->aid && $this->packet_id == "0069"){
				//echo "AccountID got from 0069\n";
				$this->aid = $this->unpack2("@8/L", $this->stream);
			}
			// ####
			if (array_key_exists($this->packet_id, $this->p_lens)) {
				$this->packet_length = $this->p_lens[$this->packet_id];
				if(!$this->packet_length) {
					// Get packet length from packet
					$this->packet_length = $this->unpack2("@2/S", $this->stream);
				}
				if (strlen($this->stream) < $this->packet_length) {
					// Packet is not complete
					echo "| $this->packet_num |  $this->packet_dir  | $this->packet_id | Packet Not Complete\n";
					$this->prev_packet = $this->stream;
					$this->prev_packet_dir = $this->packet_dir;
					break;
				}
				//echo $this->packet_dir . " " . $this->packet_id . " " . strlen($this->stream) . " " . $this->packet_length . "\n";
				//echo bin2hex($this->stream) ."\n";
				
				// copy and removed single packet from stream
				$this->packet = substr($this->stream,0,$this->packet_length);
				$this->stream = substr($this->stream,$this->packet_length);
				
				if(!array_key_exists($this->packet_id, $this->p_funcs)) {
					$this->p_funcs[$this->packet_id] = "NO_FUNC_DEFINED";
				}
				$this->packet_desc = str_pad($this->p_funcs[$this->packet_id], 50, " ");
				$this->packet_desc = "| $this->packet_num |  $this->packet_dir  | $this->packet_id | $this->packet_desc |";
				$this->packet_pointer = 2;
				if(function_exists($this->p_funcs[$this->packet_id])) {
					$this->p_funcs[$this->packet_id]($this);
					//$this->end_packet();
				} else {
					echo $this->packet_desc . "\n";
				}
			} else {
				echo "Packet length not found for $this->packet_id\n";
			}
		}
		echo $this->br;
	}
}
?>