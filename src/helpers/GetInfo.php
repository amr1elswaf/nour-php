<?php
namespace Nour\helpers;
use Swoole\Database\MysqliProxy;
use DateTime;
// L7 fix: كان `IsValidSomething::isValidDateFormat` بيتنادى من غير `use`،
// فالـ autoload كان بيدوّر `Nour\helpers\IsValidSomething` (بالصدفة في نفس
// الـ namespace فاشتغل) لكن لو الـ class انتقل، الكود يكسر.
// IsValidSomething موجود نفس الـ namespace فمش محتاج `use`، لكن نضيفه
// للوضوح.
final class GetInfo {
    
    public static function get(MysqliProxy $mysql,string $key):array{
        $stmt = $mysql->prepare('SELECT data FROM `system_data` WHERE `name` = ? ;');
        $stmt->bind_param('s',$key);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if($result->num_rows == 0 ){
            return array();
        }else{
            $result_data = $result->fetch_assoc();
            return (array) json_decode($result_data['data']);
        }
    }

    public static function get_time_dif($date):object{

    $startDate = date('Y-m-d').' '.date('H:i').':'.date('s');
    if(!IsValidSomething::isValidDateFormat($date)){
        return (object)[];
    }
    $endDate =  $date;
    
    $startDateObj = new DateTime($startDate);
    $endDateObj = new DateTime($endDate);
    
    $diff =  $startDateObj->diff($endDateObj);
    //$date = array('year'=>"$diff->y",'month'=>"$diff->m",'day'=>"$diff->d",'hour'=>"$diff->h",'mint'=>"$diff->i", 'second'=>"$diff->s");
return $diff ;
}
}
/*
         $mysql->autocommit(false);
        try{
            $mysql->begin_transction();
            //code

            
            $mysql->commit();
            $mysql->autocommit(true);

        }catch(Exception $e){

            $mysql->rollback();
            $mysql->autocommit(true);

        }
 */