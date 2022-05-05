<?php

   require 'zipfile.lib.php';

   $Back = new ZipFile("../backup/pinewood.by.zip",9);
   $Dirs = array("../backup");
   $File = array();
   $Back->Set_Exclude($Dirs,$File);
   $Back->Set_PartSize(1024*1024*1024);
   $Back->Ext=array();
   $Back->Set_Method(0);
   $Back->Add_Tree("..");

?>