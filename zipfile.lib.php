<?php

class ZipFile {

  // BZIP2   - bzcompress
  // DEFLATE - gzdeflate

  var $Debug = 1;

  var $Zip_Name = "";
  var $ctrl_dir = array();           // Central Directory
  var $eof_ctrl_dir = "\x50\x4b\x05\x06\x00\x00\x00\x00";    // End of Central Directory record

  var $ExcDirs  = array();
  var $ExcFiles = array();
  var $CMethod = 0;            // Compression method by default = DEFLATE(0), or BZIP2(1)
  var $CLevel  = 9;            // Compression level by default = 5

  var $PartSize = 0;           // Max size of parts of archive files (0 - no divide by parts)
  var $PartCurr = 0;           // Current number of part
  var $PartLen  = 0;           // Current length of written data

  var $old_offset = 0;
  var $len_data = 0;
  var $utf_flag = 0;
  var $Fd;

  var $Ext = array(
    '7z','a00','a01','a02','ace','ain','alz','apz','ar','arc','arh','ari','arj','ark',
    'axx','b64','ba','bh','bhx','boo','bz','bz2','bza','bzip','bzip2','c00','c01','c02',
    'cab','car','cb7','cbr','cbz','cp9','cpt','dd','deb','dgc','dist','djvu','dl_','dz',
    'ecs','efw','epi','ex_','f','fdp','gca','gif','gz','gza','gzi','gzip','ha','hbc','hbc2',
    'hbe','hki','hki1','hki2','hki3','hpk','hyp','ice','ipg','ipk','ish','ita','j','jar.pac',
    'jgz','jic','jpeg','jpg','kgb','lbr','lemon','lha','lnx','lqr','lz','lzh','lzma','lzo',
    'lzx','md','mou','mzp','oar','oz','p7m','pack.gz','package','pae','pak','paq6','paq7',
    'paq8','par','par2','pbi','pcv','pea','pet','pf','pim','pit','piz','pkg','png','pup',
    'pup','puz','qda','r0','r00','r01','r02','r03','r1','r2','r21','r30','rar','rev','rk',
    'rnc','rp9','rpm','rte','rzs','s00','s01','s02','sar','sdn','sea','sen','sfs','sfx',
    'sh','shar','shr','sit','sitx','spt','sqx','sqz','tar','tar.gz','tar.xz','taz','tbz',
    'tbz2','tg','tgz','tlz','tlzma','tsk','txz','tz','uc2','uha','vem','vsi','wad','war',
    'wot','xef','xez','xmcdz','xpi','xx','xz','y','z','z01','z02','z03','z04','z05','z06',
    'z07','z08','z09','zap','zi','zip','zipx','zix','zl','zoo','zpi','zz');
  // **********************************************************************************

  function __construct($iName, $iLevel=5) {
    try {
      if (strtolower(substr($iName,-4,4)) != ".zip") $iName .= ".zip";
      $this->Zip_Name = $iName;
      $this->CLevel = (($iLevel>=0) && ($iLevel<=9)) ? $iLevel : 5;
      $this->Fd = fopen($iName, "w");
      $this->Logger("<h1>Start</h1>");
    } catch (Exception $e) {
      print $e->getMessage();
    }
  }

  // **********************************************************************************

  function __destruct() {
    $ctrldir = implode("",$this->ctrl_dir);
    $this->Writer(
      $ctrldir.
      $this->eof_ctrl_dir.
      pack("v", sizeof($this->ctrl_dir)).     // total number of entries "on this disk"
      pack("v", sizeof($this->ctrl_dir)).     // total number of entries overall
      pack("V", strlen($ctrldir)).      // size of central dir
      // pack("V", strlen($data)).
      pack("V", $this->len_data).       // offset to start of central dir
      "\x00\x00"          // .zip file comment length
    );
    fclose($this->Fd);
    $this->Logger("<h1>Done</h1>");
    // clear all keys of this object
    foreach ($this as $key => $value) {
      unset($this->$key);
    }
  }

  // **********************************************************************************
  // Set_Exclude($Dirs,$Files) - Add a directory and files to exclude from processing
  // **********************************************************************************

  function Set_Exclude($iExcDir,$iExcFiles) {
    $this->ExcDirs  = $iExcDir;
    $this->ExcFiles = $iExcFiles;
  }

  // **********************************************************************************
  // Set_PartSize($iSize) - Set an length of parts of the archive
  // **********************************************************************************

  function Set_PartSize($iPart) {
    if (($iPart == 0) || ($iPart>1023)) {
      $this->PartSize = $iPart;
    } else {
      throw new Exception('PartSize must be 0 or > 1023!');
    }
  }

  // **********************************************************************************
  // Set_Method($iMethod) - Set Method for compress processing
  // **********************************************************************************

  function Set_Method($iMethod) {
    if ($iMethod = 1) {
      $this->CMethod = 1;
    } else {
      $this->CMethod = 0;
    }
  }

  // **********************************************************************************
  // Logger($iMessage) - Print debug information
  // **********************************************************************************

  function Logger($iMes) {
    if ($this->Debug) {
       echo "$iMes\n";
    }
  }

  // **********************************************************************************
  // Writer($iData) - Write data to file
  // **********************************************************************************

  function Writer($iData) {
    $Data = $iData;
    if ($this->PartSize>0) {
      while($Data) {
        if (strlen($Data) + $this->PartLen >= $this->PartSize) {
          fwrite($this->Fd,substr($Data,0,$this->PartSize-$this->PartLen));
          fclose($this->Fd);
          $Data = substr($Data,$this->PartSize-$this->PartLen);
          $this->PartLen = 0;
          // переименование первого
          if ($this->PartCurr == 0) rename ($this->Zip_Name,$this->Zip_Name.".001");
          // новый файл
          $this->Fd = fopen($this->Zip_Name.sprintf(".%03d",++$this->PartCurr+1), "w");
        } else {
          fwrite($this->Fd,$Data);
          $this->PartLen += strlen($Data);
          $Data = "";
        }
     }
    } else {
      fwrite($this->Fd,$Data);
    }
  }

  // **********************************************************************************
  // add_Dir($name) - Adds a directory to the zip with the name $name
  // **********************************************************************************

  function Add_Dir($name) { // Adds a directory to the zip with the name $name
    $name = str_replace("\\", "/", $name);
    $name = preg_replace("/^(\.\/)(.*)$/", "$2", $name);
    if ((!$name) || ($name == ".")) return;
    $fr = "\x50\x4b\x03\x04";
    $fr .= "\x0a\x00";        // version needed to extract
    $fr .= "\x00\x00";        // general purpose bit flag
    $fr .= "\x00\x00";        // compression method
    $fr .= "\x00\x00\x00\x00";      // last mod time and date
    $fr .= pack("V",0);       // crc32
    $fr .= pack("V",0);       //compressed filesize
    $fr .= pack("V",0);       //uncompressed filesize
    $fr .= pack("v",strlen($name));     //length of pathname
    $fr .= pack("v", 0);      //extra field length
    $fr .= $name;
    // end of "local file header" segment

    // no "file data" segment for path

    // "data descriptor" segment (optional but necessary if archive is not served as file)
    $fr .= pack("V",0); //crc32
    $fr .= pack("V",0); //compressed filesize
    $fr .= pack("V",0); //uncompressed filesize

    // add this entry to array
    $this->Writer($fr);
    $this->len_data += strlen($fr);

    // -- $new_offset = strlen(implode("", $this->datasec));
    $new_offset = $this->len_data;

    // ext. file attributes mirrors MS-DOS directory attr byte, detailed
    // at http://support.microsoft.com/support/kb/articles/Q125/0/19.asp

    // now add to central record
    $cdrec = "\x50\x4b\x01\x02";
    $cdrec .="\x00\x00";           // version made by
    $cdrec .="\x14\x00";           // version needed to extract
    $cdrec .="\x00\x00";           // general purpose bit flag
    $cdrec .="\x00\x00";           // compression method
    $cdrec .="\x00\x00\x00\x00";   // last mod time and date
    $cdrec .= pack("V",0);         // crc32
    $cdrec .= pack("V",0);         //compressed filesize
    $cdrec .= pack("V",0);         //uncompressed filesize
    $cdrec .= pack("v", strlen($name) ); //length of filename
    $cdrec .= pack("v", 0 );       //extra field length
    $cdrec .= pack("v", 0 );       //file comment length
    $cdrec .= pack("v", 0 );       //disk number start
    $cdrec .= pack("v", 0 );       //internal file attributes
    $cdrec .= pack("V", 16 );      //external file attributes  - 'directory' bit set

    $cdrec .= pack("V", $this->old_offset); //relative offset of local header
    $this->old_offset = $new_offset;

    $cdrec .= $name;
    // optional extra field, file comment goes here, save to array
    $this->ctrl_dir[] = $cdrec;
    // -- $this->dirs[] = $name;
  }

  // **********************************************************************************
  // Add_File($data, $name) - Adds a file to the path specified by $name with the
  //                          contents $data
  // **********************************************************************************

  function Add_File($name) {
    $name = str_replace("\\", "/", $name);
    $name = preg_replace("/^(\.\/)(.*)$/", "$2", $name);
    if (!$name) return;
    // check to skip compress by extention
    $Skip = 0;
    foreach ($this->Ext as $I) {
      if (strtolower(substr($name,-(strlen($I)+1),strlen($I)+1)) == ".".$I) {
        $Skip = 1;
        break;
      }
    }
    try {
      $data = implode("",file($name));
      $cMethod = "\x00\x00";           // compression method - STORE
      if (!$Skip) {
        if ($this->CLevel > 0) {
          if ($this->CMethod == 1) {
            $cMethod = "\x0C\x00";       // compression method - BZIP2
          } else {
            $cMethod = "\x08\x00";       // compression method - DEFLATE
          }
        }
      }

      $name = str_replace("\\", "/", $name);

      $fr = "\x50\x4b\x03\x04";
      $fr .= "\x0A\x00";         // version needed to extract
      $fr .= "\x00\x00";         // general purpose bit flag

      $unc_len = strlen($data);
      $crc = crc32($data);
      if (($this->CLevel>0) && (!$Skip)) {
        $zdata = ($this->CMethod == 0) ? gzdeflate($data,$this->CLevel) : bzcompress($data,$this->CLevel);
        if (strlen($zdata)>=strlen($data)) {
          $cMethod = "\x00\x00";
          $zdata = $data;
        }
      } else {
        $zdata = $data;
      }

      $fr .= $cMethod;                           // compression method
      $fr .= "\x00\x00\x00\x00";                 // last mod time and date
      $c_len = strlen($zdata);                   // data length
      $fr .= pack("V", $crc);        // crc32
      $fr .= pack("V", $c_len);      // compressed filesize
      $fr .= pack("V", $unc_len);      // uncompressed filesize
      $fr .= pack("v", strlen($name) );    // length of filename
      $fr .= pack("v", 0 );        // extra field length
      $fr .= $name;
      // end of "local file header" segment

      // "file data" segment
      $fr .= $zdata;

      // "data descriptor" segment (optional but necessary if archive is not served as file)
      $fr .= pack("V",$crc);       // crc32
      $fr .= pack("V",$c_len);       // compressed filesize
      $fr .= pack("V",$unc_len);     // uncompressed filesize

      // add this entry to array
      $this->Writer($fr);
      $this->len_data += strlen($fr);
      $new_offset = $this->len_data;

      // now add to central directory record
      $cdrec = "\x50\x4b\x01\x02";
      $cdrec .="\x00\x00";    // version made by
      $cdrec .="\x0A\x00";    // version needed to extract
      $cdrec .="\x00\x00";    // general purpose bit flag
      $cdrec .= $cMethod;           // compression method
      $cdrec .="\x00\x00\x00\x00";  // last mod time & date
      $cdrec .= pack("V",$crc);     // crc32
      $cdrec .= pack("V",$c_len);   //compressed filesize
      $cdrec .= pack("V",$unc_len); //uncompressed filesize
      $cdrec .= pack("v", strlen($name) ); //length of filename
      $cdrec .= pack("v", 0 );      //extra field length
      $cdrec .= pack("v", 0 );      //file comment length
      $cdrec .= pack("v", 0 );      //disk number start
      $cdrec .= pack("v", 0 );      //internal file attributes
      $cdrec .= pack("V", 32 );     //external file attributes - 'archive' bit set

      $cdrec .= pack("V", $this->old_offset); //relative offset of local header
      $this->old_offset = $new_offset;

      $cdrec .= $name;
      // optional extra field, file comment goes here
      // save to central directory
      $this->ctrl_dir[] = $cdrec;
    } catch (Exception $e) {
      print $e->getMessage();
    }
  }

  // **********************************************************************************
  // Add_Tree($name) - Adds a root of tree directories for scan and processing
  // **********************************************************************************

  function Add_Tree($root) {
    if(!$root) $root = ".";
    $this->Add_Dir($root.'/');
    preg_match('/(.+)\/.+/', $this->Zip_Name,$Matches);
    $Arc_Dir = $Matches[0];
    $this->Logger("<ul>");
    $dh = opendir($root);
    while (false !== ($file = readdir($dh))) {
      if ($file != "." && $file != "..") {
        if (is_dir($root.'/'.$file)) {
          if ($root.'/'.$file != $Arc_Dir) {
            if (!in_array($root.'/'.$file,$this->ExcDirs)) {
              $this->Logger("<li><b>".$root."/".$file."</b></li>");
              $this->Add_Tree($root.'/'.$file);
            } else {
              $this->Logger("<li><b><font color=\"red\">".$root."/".$file."</font></b></li>");
            }
          }
        } else {
          if (!in_array($root.'/'.$file,$this->ExcFiles)) {
            if ($this->Zip_Name != $root.'/'.$file) {
              $this->Logger("<li>".$root."/".$file."</li>");
              $this->Add_File($root.'/'.$file);
            }
          } else {
            $this->Logger("<li><font color=\"red\">".$root."/".$file."</font></li>");
          }
        }
      }
    }
    $this->Logger("</ul>");
    closedir($dh);
  }
}
?>