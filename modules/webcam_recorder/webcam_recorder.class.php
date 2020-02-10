<?php
class webcam_recorder extends module {
	function __construct() {
		$this->name="webcam_recorder";
		$this->title="WEBCam Recorder";
		$this->module_category="<#LANG_SECTION_APPLICATIONS#>";
		$this->version = '1.0';
		$this->checkInstalled();
	}

	function saveParams($data=1) {
		$p=array();
		if (IsSet($this->id)) {
			$p["id"]=$this->id;
		}
		if (IsSet($this->view_mode)) {
			$p["view_mode"]=$this->view_mode;
		}
		if (IsSet($this->edit_mode)) {
			$p["edit_mode"]=$this->edit_mode;
		}
		if (IsSet($this->tab)) {
			$p["tab"]=$this->tab;
		}
		return parent::saveParams($p);
	}

	function getParams() {
		global $id;
		global $mode;
		global $view_mode;
		global $edit_mode;
		global $tab;
		if (isset($id)) {
			$this->id=$id;
		}
		if (isset($mode)) {
			$this->mode=$mode;
		}
		if (isset($view_mode)) {
			$this->view_mode=$view_mode;
		}
		if (isset($edit_mode)) {
			$this->edit_mode=$edit_mode;
		}
		if (isset($tab)) {
			$this->tab=$tab;
		}
	}

	function run() {
		global $session;
		$out=array();
		if ($this->action=='admin') {
			$this->admin($out);
		} else {
			$this->usual($out);
		}
		if (IsSet($this->owner->action)) {
			$out['PARENT_ACTION']=$this->owner->action;
		}
		if (IsSet($this->owner->name)) {
			$out['PARENT_NAME']=$this->owner->name;
		}
		$out['VIEW_MODE']=$this->view_mode;
		$out['EDIT_MODE']=$this->edit_mode;
		$out['MODE']=$this->mode;
		$out['ACTION']=$this->action;
		$this->data=$out;
		$p=new parser(DIR_TEMPLATES.$this->name."/".$this->name.".html", $this->data, $this);
		$this->result=$p->result;
	}

	function admin(&$out) {
		$this->getConfig();
		
		if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
			//Если винда блочим и выкидываем синий экран
			$out['WIN_USER'] = 1;
			die();
		}
		
		if($this->mode == 'recording' && !empty($this->view_mode)) {
			//Имитация движения
			$this->recording($this->view_mode);
			$this->redirect("?");
		}
		
		if($this->mode == 'delete_camera' && !empty($this->view_mode)) {
			//Удаляем файлы
			$data = SQLSelectOne("SELECT * FROM `webcam_recorder` WHERE ID = '".dbSafe($this->view_mode)."' ORDER BY ID");
			$this->rmRec($data['PATH'].'/');
			//Удаление камеры
			SQLExec("DELETE FROM `webcam_recorder` WHERE ID = '".dbSafe($this->view_mode)."' LIMIT 1");
			$this->redirect("?");
		}
		
		if($this->view_mode == 'add_camera') {
			$arrayDevice = explode('crw-', shell_exec('ls -l /dev/ | grep video'));
			
			foreach($arrayDevice as $value) {
				if($value != '') $generateHint .= 'crw-'.$value.'<br>';
			}
			
			$out['FFMPEG_VIDEO_DEV'] = $generateHint;
		}
		
		if($this->view_mode == 'add_camera_DB') {
			global $cameraName;
			global $deviceAddr;
			global $howSec;
			global $codec;
			global $folderPath;
			global $takePhoto;
			global $resol;
			global $kadr;
			global $linked_object1;
			global $linked_property1;
			global $linked_object2;
			global $linked_property2;
			
			$rand = rand(1000, 9999);
			
			//Простые проверки на дурака
			if($cameraName == '') $cameraName = 'cam'.$rand;
			if($howSec == '') $howSec = 10;
			if($codec == '') $codec = 'libx264';
			if($folderPath == '') $folderPath = '/var/www/html/cms/cached/webcam_recorder/cam'.$rand;
			if($takePhoto == '') $takePhoto = 1;
			if($resol == '') $resol = '640x480';
			if($kadr == '') $kadr = '15';
			
			//Подготовим массив для записи
			$array['CAM_NAME'] = $cameraName;
			$array['DEVICE_ID'] = $deviceAddr;
			$array['SECOND'] = $howSec;
			$array['CODEC'] = $codec;
			$array['PATH'] = $folderPath;
			$array['PHOTO'] = $takePhoto;
			$array['RESOLUTION'] = $resol;
			$array['BITRATE'] = $kadr;
			$array['LINKED_OBJECT1'] = $linked_object1;
			$array['LINKED_PROPERTY1'] = $linked_property1;
			$array['LINKED_OBJECT2'] = $linked_object2;
			$array['LINKED_PROPERTY2'] = $linked_property2;
			$array['ADDTIME'] = date('d.m.Y H:i:s');
			
			SQLInsert('webcam_recorder', $array);
			
			$this->createFolder($folderPath.'/');
			
			$this->config['EMPTY_CAMS'] = 1;
			$this->saveConfig();
			
			$this->redirect("?");
		}
		
		//Выгружаем массив камер
		$out['PROPERTIES'] = SQLSelect("SELECT * FROM `webcam_recorder` ORDER BY ID");
			
		//Флаг на то, есть ли камеры
		$out['EMPTY_CAMS'] = $this->config['EMPTY_CAMS'];
		$out['VERSION_MODULE'] = $this->version;
		$out['FFMPEG_STATUS'] = (shell_exec('ffmpeg -h')) ? 1 : 0;
	}
	
	function rmRec($path) {
		if (is_file($path)) return unlink($path);
		if (is_dir($path)) {
			foreach(scandir($path) as $p) if (($p!='.') && ($p!='..'))
			$this->rmRec($path.DIRECTORY_SEPARATOR.$p);
			return rmdir($path); 
		}
		return false;
	}
	
	function createFolder($path) {
		//Проверим есть ли папка с сегоднешней датой
		if(!is_dir($path)) {
			mkdir($path, 0755, true);
		} 
		return !is_dir($path.'/');
	}
	
	function recording($camID) {
		$data = SQLSelectOne("SELECT * FROM `webcam_recorder` WHERE ID = '".dbSafe($camID)."' ORDER BY ID");
		$dateTimeName = date('dmY_His', time()).'_'.rand(1000, 9999);
		
		$this->createFolder($data['PATH'].'/'.$dateTimeName.'/');
		
		//Делаем фото
		if($data["PHOTO"] == 1) {
			exec('sudo timeout -s INT 60s ffmpeg -f video4linux2 -i '.$data["DEVICE_ID"].' -f image2 -s '.$data["RESOLUTION"].' -vframes 1 -y '.$data['PATH'].'/'.$dateTimeName.'/photo.jpg');
		}
		
		switch($data["SECOND"]) {
			case '5':
				$durationRecord = '00:00:05';
				break;
			case '10':
				$durationRecord = '00:00:10';
				break;
			case '15':
				$durationRecord = '00:00:15';
				break;
			case '25':
				$durationRecord = '00:00:25';
				break;
			case '40':
				$durationRecord = '00:00:40';
				break;
			case '60':
				$durationRecord = '00:01:00';
				break;
			case '120':
				$durationRecord = '00:02:00';
				break;
			case '600':
				$durationRecord = '00:05:00';
				break;
		}
		
		//Пишем видео
		exec('sudo timeout -s INT 120s ffmpeg -y -f video4linux2 -i '.$data["DEVICE_ID"].' -t '.$durationRecord.' -f mp4 -r '.$data['BITRATE'].' -s '.$data["RESOLUTION"].' -c:v '.$data['CODEC'].' '.$data['PATH'].'/'.$dateTimeName.'/video.mp4');		
	}
	
	function usual(&$out) {
		$this->admin($out);
	}

	function install($data='') {
		parent::install();
	}
	
	function uninstall() {
		SQLExec('DROP TABLE IF EXISTS webcam_recorder');
	}	
	
	function dbInstall($data = '') {

		$data = <<<EOD
webcam_recorder: ID int(10) unsigned NOT NULL auto_increment
webcam_recorder: CAM_NAME varchar(100) NOT NULL DEFAULT ''
webcam_recorder: DEVICE_ID varchar(100) NOT NULL DEFAULT ''
webcam_recorder: SECOND varchar(100) NOT NULL DEFAULT ''
webcam_recorder: CODEC varchar(100) NOT NULL DEFAULT ''
webcam_recorder: PATH varchar(100) NOT NULL DEFAULT ''
webcam_recorder: PHOTO varchar(100) NOT NULL DEFAULT ''
webcam_recorder: RESOLUTION varchar(100) NOT NULL DEFAULT ''
webcam_recorder: BITRATE varchar(100) NOT NULL DEFAULT ''
webcam_recorder: LINKED_OBJECT1 varchar(255) NOT NULL DEFAULT ''
webcam_recorder: LINKED_PROPERTY1 varchar(255) NOT NULL DEFAULT ''
webcam_recorder: LINKED_OBJECT2 varchar(255) NOT NULL DEFAULT ''
webcam_recorder: LINKED_PROPERTY2 varchar(255) NOT NULL DEFAULT ''
webcam_recorder: ADDTIME varchar(255) NOT NULL DEFAULT ''
	
EOD;
		parent::dbInstall($data);
	}
}