<?php declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/product_brand.php';
$checks=0;
function check_brand(bool $ok,string $what): void { global $checks; if(!$ok)throw new RuntimeException($what); $checks++; }
$saved=getenv('ECW_PRODUCT_BRAND');
try {
    putenv('ECW_PRODUCT_BRAND');
    check_brand(ecw_product_brand()['profile']==='original','Existing PCs without a preference retain their original brand.');
    putenv('ECW_PRODUCT_BRAND=original');
    check_brand(ecw_product_brand()['name']==='Case Workspace','Original name.');
    putenv('ECW_PRODUCT_BRAND=eleon');
    check_brand(ecw_product_brand()['name']==='Eleon','Eleon name.');
    putenv('ECW_PRODUCT_BRAND=../../bad');
    check_brand(ecw_product_brand()['profile']==='original','Unknown profiles cannot select arbitrary assets.');
    putenv('ECW_PRODUCT_BRAND=original');
    check_brand(ecw_product_brand_for_page(['role'=>'member'],['brand_preview'=>'eleon'])['profile']==='original','Members cannot override branding.');
    check_brand(ecw_product_brand_for_page(['role'=>'admin'],['brand_preview'=>'eleon'])['profile']==='eleon','Admin page preview.');
    check_brand(ecw_product_brand()['profile']==='original','Preview does not persist across pages.');
    check_brand(ecw_product_brand_for_page(['role'=>'admin'],['brand_preview'=>['eleon']])['profile']==='original','Malformed preview ignored.');
    $identities=[];
    foreach(['original','eleon']as$profile){
        $b=ecw_product_brand($profile);$m=ecw_product_manifest($b);$identities[]=$m['id'];
        check_brand($m['name']===$b['name']&&$m['short_name']===$b['short_name'],'Manifest uses the selected profile.');
        foreach($m['icons']as$icon){
            $file=dirname(__DIR__).'/web/'.$icon['src'];$info=getimagesize($file);
            check_brand($info && $info['mime']==='image/png' && $info[0].'x'.$info[1]===$icon['sizes'],'Manifest icon has the promised real PNG dimensions.');
        }
        foreach($m['shortcuts']as$s)check_brand(str_starts_with($s['icons'][0]['src'],$b['icons']),'Shortcut uses selected icon.');
        check_brand(is_file(dirname(__DIR__).'/web/'.$b['icons'].'favicon-32.png'),'Profile favicon exists.');
    }
    check_brand($identities[0]===$identities[1],'Switching profiles preserves installed application identity.');
    foreach(['favicon-32.png','apple-touch-icon.png','icon-192.png','icon-512.png','maskable-512.png']as$file)
        check_brand(hash_file('sha256',dirname(__DIR__).'/web/assets/icons/'.$file)===hash_file('sha256',dirname(__DIR__).'/web/assets/brands/original/'.$file),'Original icon preserved byte for byte.');
    echo "Product branding: $checks checks passed.\n";
}finally{putenv($saved===false?'ECW_PRODUCT_BRAND':'ECW_PRODUCT_BRAND='.$saved);}
