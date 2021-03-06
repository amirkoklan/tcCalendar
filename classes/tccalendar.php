<?php

class tcCalendar {

	function tcCalendar($cal_id, $from_time = null, $to_time = null) {
		
		$ezphpicalendarini = eZINI::instance( 'tccalendar.ini' );
		$contentini = eZINI::instance( 'content.ini' );
		$myroot = $contentini->variable( 'NodeSettings', 'RootNode' );
		
		$is_master_id = $ezphpicalendarini->variable( 'ClassSettings', 'IsMasterAttributeIdentifier' );
		
		$this->node_id = $cal_id;

		$cal_node = eZContentObjectTreeNode::fetch($cal_id);

		$cal_node_data = $cal_node->dataMap();
		
		$this->is_master = (array_key_exists($is_master_id, $cal_node_data)) ? $cal_node_data[$is_master_id]->content() : true;
		
		if ($this->is_master) $cal_id = $myroot;
		
		$eventclasses = $ezphpicalendarini->variable( "ClassSettings", "EventClassIds" );
		$calclasses = $ezphpicalendarini->variable( "ClassSettings", "CalClassIds" );
		
		$this->title_id = $ezphpicalendarini->variable( "ClassSettings", "TitleAttributeIdentifier");
		$this->location_id = $ezphpicalendarini->variable( "ClassSettings", "LocationAttributeIdentifier");
		$this->calcol_id = $ezphpicalendarini->variable( "ClassSettings", "CalColorAttributeIdentifier");
		$this->sd = $ezphpicalendarini->variable( "ClassSettings", "StartDateAttributeIdentifier");
		$this->st = $ezphpicalendarini->variable( "ClassSettings", "StartTimeAttributeIdentifier");
		$this->ed = $ezphpicalendarini->variable( "ClassSettings", "EndDateAttributeIdentifier");
		$this->et = $ezphpicalendarini->variable( "ClassSettings", "EndTimeAttributeIdentifier");
		$this->r = $ezphpicalendarini->variable( "ClassSettings", "EventClassRepeatAttributes");
		$this->HasPopup = $ezphpicalendarini->variable( "PopupOptions", "HasPopup");
		$this->col_r=array();
		
		if (!is_array($calclasses)) $eventclasses = array($calclasses);
		if (!is_array($eventclasses)) $eventclasses = array($eventclasses);
		
		$params = array('ClassFilterType' => 'include', 'ClassFilterArray' => $calclasses, 'AsObject' => false);
		
		$cals = eZContentObjectTreeNode::subTreeByNodeID( $params, $cal_id );
		
		$cal_ids = array($cal_id);
		
		foreach ($cals as $c) {
			$cal_ids[] = $c['main_node_id'];
		}

		$params = array('ClassFilterType' => 'include', 'ClassFilterArray' => $eventclasses, 'SortBy' => array(array('attribute', true, "event/".$this->sd),array('name', true)));
		
		$params['Depth'] = 1;
		$params['DepthOperator'] = 'eq';
		
		$this->ed_i = false;
		$this->sd_i = false;

		$attribute_filter = array();
		if ($to_time != null) {
			$attribute_filter[] = array("event/".$this->sd, "between", array(0,strtotime($to_time.'T23:59:59')));
			$this->ed_i = strtotime($to_time.'T23:59:59');
		}
		if ($from_time != null) {
			$this->sd_i = strtotime($from_time);
			$attribute_filter[] = array("event/".$this->ed, "not_between", array(1,strtotime($from_time)));
			//$attribute_filter[] = array("event/".$this->sd, "not_between", array(0,strtotime($from_time)));
		}
		if (count($attribute_filter)) $params['AttributeFilter'] = $attribute_filter;

		$events = eZContentObjectTreeNode::subTreeByNodeID( $params, $cal_ids );
		      
		if (in_array($cal_node->object()->contentClass()->attribute('id'), $eventclasses) && $for_output) $events = array($cal_node);

		$this->events = $events;
		
	}
	
	function monthtojson() {
		$output =  "var tcevents = [\r\n";
		foreach($this->events as $e) {

			$e_o = $this->eventtoobject($e);
			if ($e_o === false) continue; 
			
			$diff = $e_o->ts_e - $e_o->ts_s;
			$myclass_id = $e->object()->contentClass()->attribute('id');
			$repeaters = $this->r;
			$normal = true;
			if (array_key_exists($myclass_id, $repeaters)) {
				$dm = $e->dataMap();
				if (array_key_exists($repeaters[$myclass_id], $dm) && $dm[$repeaters[$myclass_id]]->hasContent()) {
					$mycontent = $dm[$repeaters[$myclass_id]]->content();
					if (strpos($mycontent->text, 'repeats') !== false && strpos($mycontent->text, 'repeats=never') === false) {
						$normal = false;
						$start_times = $dm[$repeaters[$myclass_id]]->content()->get_timestamps();
						foreach ($start_times as $t) {
							
							if ($this->sd_i && $t < $this->sd_i) continue;
							if ($this->ed_i && $t > $this->ed_i) continue;
							
							$mytime = new eZDateTime($t);
							$mytime_e = new eZDateTime($t + $diff);
							$e_o->start = "new Date(" . $mytime->year() . ", " . (floor($mytime->month()) -1) . ", " . $mytime->day() . ", " . $e_o->hour . ", " . $e_o->minute .")";
							$e_o->end = "new Date(" . $mytime_e->year() . ", " . (floor($mytime_e->month()) -1) . ", " . $mytime_e->day() . ", " . $e_o->hour . ", " . $e_o->minute .")";
						
							$output .= $this->eventobjecttojson($e_o);
						}
					}
				}
			} 
			if ($normal && $e_o->status != 'error_without_repeat') {
				$output .= $this->eventobjecttojson($e_o);
			}
		}
		
		$output .= "];\r\n";
		$output .= "var tc_cal_id = " . $this->node_id . ";";
		return $output;
	}
	
	function eventtoobject($e) {
		$this->allDay = false;
		$parent_node_id = $e->attribute('parent_node_id');
		
		if (!array_key_exists('node_'.$parent_node_id, $this->col_r)) {
			$parent_data = $e->fetchParent()->dataMap();
			if (!array_key_exists($this->calcol_id,$parent_data)) {
				$parent_col = '#000000';
			} else {
				$parent_col = $parent_data[$this->calcol_id]->content();
			}
			$this->col_r['node_'.$parent_node_id] = $parent_col;
		} else {
			$parent_col = $this->col_r['node_'.$parent_node_id];
		}
		$event_id = $e->attribute('node_id');
		$objData = $e->dataMap();
		if (array_key_exists('hide_from_calendar', $objData) && $objData['hide_from_calendar']->content()) return false; 
		$e_o = new stdClass();
		if (class_exists('tcEventDataFetcher')) {
			$event_data = tcEventDataFetcher::fetchData($e);
			foreach($event_data as $event_data_k => $event_data_v) {
				$e_o->$event_data_k = $event_data_v;
			}
		} else {
			$e_o->backgroundColor = $e_o->backgroundColor = '"'.$parent_col.'"';
		}
		$e_o->id = $event_id;
		if (array_key_exists($this->title_id, $objData) && is_object($objData[$this->title_id])) {
			$e_o->title = '"'.addslashes(preg_replace('/[^(\x20-\x7F)]*/','', $objData[$this->title_id]->content())).'"';
		}
		if (array_key_exists($this->location_id, $objData) && is_object($objData[$this->location_id])) {
			$e_o->location = '"'.addslashes(preg_replace('/[^(\x20-\x7F)]*/','', $objData[$this->location_id]->content())).'"';
		}
		$e_o->start = $this->get_event_start($objData, $e_o);
		$e_o->end = $this->get_event_end($objData);
		if ($this->allDay === false) $e_o->allDay = 'false';
		$e_o->HasPopup = ($this->HasPopup == enabled) ? 'true' : 'false';

		// Use SiteLink extension if available
		if (class_exists("SiteLink")) {
			$sitelink = new SiteLink($e);
			$sitelink_path = end($sitelink->path());
			$e_o->url = '"' . $sitelink_path['url_alias'] . '"';

		// Use old method if SiteLink not loaded.
		} else {
			$e_o->url = '"/' . $e->urlAlias(). '"';
		}
		return $e_o;
	}
	
	function eventobjecttojson($e_o) {
		$out = chr(123);
		foreach($e_o as $k=>$v) {
			if ($v && $k !='status') $out .= "$k: $v,\r\n";
		}
		return preg_replace("/,\r\n$/", "", $out) . chr(125) . ",\r\n";
	}
	
	function get_event_start($objData, $e_o, $type=false) {
		
		if ((!is_object($objData[$this->sd])) || $objData[$this->sd]->hasContent() != 1) return false;
		$date_from = $objData[$this->sd]->content();
		if ((!is_object($objData[$this->st])) || $objData[$this->st]->hasContent() != 1) {
			$this->allDay = true;
			$time_from = new eZDateTime($date_from);
			$time_from->setHour(0);
			$time_from->setMinute(0);
			$time_from->setSecond(0);
		} else {
			$time_from = $objData[$this->st]->content();
		}
		$e_o->ts_s = $date_from->timestamp();
		$e_o->hour = $time_from->hour();
		$e_o->minute = $time_from->minute();
		$out = "new Date(" . $date_from->year() . ", " . (floor($date_from->month())-1) . ", " . $date_from->day() . ", " . $time_from->hour() . ", " . str_pad($time_from->minute(), 2, "0", STR_PAD_LEFT) .")";
		if ($type == 'fulldata') $out = strtotime((floor($date_from->month())) ."/". $date_from->day() ."/". $date_from->year() ." ".$time_from->hour().":".str_pad($time_from->minute(), 2, "0", STR_PAD_LEFT));
		
		$test_start = strtotime((floor($date_from->month())) ."/". $date_from->day() ."/". $date_from->year());
		if ($this->ed_i && $test_start > $this->ed_i) $e_o->status = 'error_without_repeat';
		
		return $out;
			 
	}
	
	function get_event_end($objData, $e_o, $type=false) {
		if (!is_object($objData[$this->ed]) || $objData[$this->ed]->attribute('data_int') == 0 || $objData[$this->ed]->attribute('data_int') == null) {
			$date_to = $objData[$this->sd]->content();
			if (($objData[$this->sd]->attribute('data_int') + (60*60*24) -1) < $this->sd_i ) $e_o->status = 'error_without_repeat';
		} else {
			$date_to = $objData[$this->ed]->content();
		}
		if (!is_object($objData[$this->et]) || $objData[$this->et]->attribute('data_int') == 0 || $objData[$this->et]->attribute('data_int') == null) {
			$time_to = $objData[$this->st]->content();
			if (is_object($time_to)) {
				$temp_ts = $time_to->timeStamp();
				$time_to->setTimeStamp($temp_ts + (60*60));
			} else {
				$time_to = new eZDateTime($date_to);
				$time_to->setHour(0);
				$time_to->setMinute(0);
				$time_to->setSecond(0);
			}
		} else {
			$time_to = $objData[$this->et]->content();
		}
		$e_o->ts_e = $date_to->timestamp();

		$out = "new Date(" . $date_to->year() . ", " . (floor($date_to->month())-1) . ", " . $date_to->day() . ", " . $time_to->hour() . ", " . str_pad($time_to->minute(), 2, "0", STR_PAD_LEFT) .")";
		if ($type == 'fulldata') $out = strtotime((floor($date_to->month())) ."/". $date_to->day() ."/". $date_to->year() ." ".$time_to->hour().":".str_pad($time_to->minute(), 2, "0", STR_PAD_LEFT));
		
		$test_end = strtotime((floor($date_to->month())) ."/". $date_to->day() ."/". $date_to->year());
		
		if ($this->sd_i && $test_end < $this->sd_i) return false;
		
		return $out;
				
	}
	
	var $node_id;
	var $events;
	var $output;
	var $sd;
	var $st;
	var $sd_i;
	var $ed;
	var $et;
	var $ed_i;
	var $r;
	var $col_r;
	var $allDay;
}

?>