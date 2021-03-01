<?php
/**
 * Created by PhpStorm.
 * User: hengliu
 * Date: 2019/5/7
 * Time: 2:59 PM
 */

require '../src/Config.php';
require '../Config.php';
require '../src/Utils.php';
require '../src/SwapApi.php';
require '../src/AccountApi.php';


use okv3\AccountApi;
use okv3\Config;
use okv3\MarginApi;
use okv3\OptionsApi;
use okv3\OthersAPI;
use okv3\SwapApi;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;
$instrumentId = "LINK-USDT-SWAP";
$kinstrumentId = "LINK-USDT-SWAP";
$kcurrency = "LINK";
$currency = "LINK";
$obj = new SwapApi(Config::$config);
$amount = 0.01;
$abedCnt = 0;
$tick = 0;
$qpd =0;
$qpk =0;
$duocnt = 0;
$kongcnt = 0;
$isAgg = false;
$sellcnt=0;
$ordersid=array();
$kongordersid=array();
//pending selling orderids
$pendingsids = array();
//pending selling prices
$pendingsps=array();

$kongpendingsids = array();
//pending selling prices
$kongpendingsps=array();

$cancelOrderRate = 1.01;
$d =0;
$totalduo=0;
$totalkong=0;
$freeze=0;
$lastKongOid='';
$lastDuoOid='';
$lastKongDone = 0;
$lastDuoDone = 0; 
$kdjcache = 0; 
$tickerPrice=0;
$cancelOids=array();
/*
function gainRatio()
{
	global $totalduo;
	global $totalkong;

	if(abs($totalduo-$totalkong) > 1)
		return 2;
	else
		return 1;
}
 */
function getMaxHighAndMinLow(&$ticks, &$max, &$min) {
     $maxHigh = $ticks[0][2];
     $minLow = $ticks[0][3];
    for ($i = 0; $i < count($ticks); $i++) {
      $t = $ticks[$i]; $high = $t[2]; $low = $t[3];
      if ($high > $maxHigh) {
        $maxHigh = $high;
      }
      if ($low < $minLow) {
        $minLow = $low;
      }
    }
        $max = $maxHigh;
        $min = $minLow;
  };
function getKdj(&$ticks, &$last, &$cur, &$ll)
{
	global $magic;
    $nineDaysTicks = array(); $days = 9;$rsvs = array();
    $ks = array(); $ds = array(); $js = array();
    $lastK=0; $lastD=0; $curK=0; $curD=0;
    $max=0; $min=0;
    $tcnt = count($ticks);
    $ts=0;
    for ($i = 0; $i < $tcnt; $i++) {
      $t = $ticks[$i]; $close = $t[4];
      array_push($nineDaysTicks,$t);
      getMaxHighAndMinLow($nineDaysTicks, $max, $min);

      if ($max == $min) {
        array_push($rsvs,0);
      } else {
        array_push($rsvs,(($close - $min) / ($max - $min) * 100));
      }
      if (count($nineDaysTicks) == $days) {
        array_shift($nineDaysTicks);;
      }
      if ($i == 0) {
        $lastK = $lastD = $rsvs[$i];
      }
      $curK = 2 / 3 * $lastK + 1 / 3 * $rsvs[$i];
     // array_push($ks,$curK);
      $lastK = $curK;

      $curD = 2 / 3 * $lastD + 1 / 3 * $curK;
      //array_push($ds,$curD);
      $lastD = $curD;
      $curJ = 3 * $curK - 2 * $curD;
      //array_push($js, $curJ);
      //echo "k:$curK d:$curD,j: $curJ \n";
      if($i == ($tcnt-1))
      {
        array_push($cur, $curK);
        array_push($cur, $curD);
        array_push($cur, $curJ);
        $ts = $t[0];
      }
      if($i == ($tcnt-2))
      {
        array_push($last, $curK);
        array_push($last, $curD);
        array_push($last, $curJ);

      }
      if($i == ($tcnt-3))
      {
        array_push($ll, $curK);
        array_push($ll, $curD);
        array_push($ll, $curJ);

      }
    }

}
//1--downward
//2--upward;
//0 no cross;
function kdjCross($cur,$magic)
{
	global $d;
//0:k,1:d,2:j
    $k = $cur[0];
        $d_ = $cur[1];
        $j = $cur[2];
	if($d)
	{
		echo "kdj:$k,$d_,$j\n";
	}
        if( ($j-$k)>$magic)
        {
             return 2;
        }
	else if(($k-$j)>$magic)
        {
             return 1;
        }
        return 0;
}
function kdjTrend($cur, $last, $magic)
{
	if(($cur[2]-$magic) > $last[2])
	{
	   return 2;
	}else if(($last[2]-$magic) > $cur[2])
	{
           return 1;
	}
	return 0;
}

function getRefHour()
{
	
    global $obj;
    global $instrumentId;
    global $d;
    global $magic;
    global $j_top;
    global $j_bottom;
    $ksecs = 3600*24; 
     $res = $obj -> getKline($instrumentId,$ksecs);
     $cnt = count($res);
     if($cnt > 60)
     {
     $p60 = array();
     for($i=59;$i>=0;$i--)
     {
        array_push($p60,$res[$i]);
     }
     $cur = array();
     $last = array();
     $ll = array();
     getKdj($p60, $last, $cur, $ll);
     $cross = kdjTrend($cur, $last, $magic);
     if($cur[2] < $j_bottom)
     {
       $cross = 2;
     }
     else if($cur[2] > $j_top)
     {
        $cross = 1;
     }
     return $cross;
     }
     return 0;
}

function getRefPrice($ksecs, &$tick, &$m60,&$mid)
{
    global $obj;
    global $instrumentId;
    global $d;
    global $magic;

     $res = $obj -> getKline($instrumentId,$ksecs);
     $cnt = count($res);
     $ts = 0;
     $min = 1000;
     $max = 0;
     $p60 = array();
     for($i=59;$i>=0;$i--)
     {
	$sou = $res[$i][4];
	if($sou < $min)
	     $min=$sou;
	if($sou > $max)
	     $max=$sou;
	$ts += $res[$i][4];
        array_push($p60,$res[$i]);
     }
     $mid = ($min+$max)/2;
     $tick = $res[0][0];
     $m60 = $ts/60;
     $cur = array();
     $last = array();
     $ll = array();
     getKdj($p60, $last, $cur, $ll);
     /*
     $cross = kdjCross($cur, $magic);
     if($cross > 0 && ($cross + kdjCross($last, 0))==3)
       return $cross;
      */
     $cross = kdjTrend($cur, $last, $magic);
    return $cross;

}
function  getTickerPrice(&$tickerduop, &$tickerkongp)
{
    global $obj;
    global $instrumentId;
    global $d;
    
    $res = $obj -> getSpecificTicker($instrumentId);
    $lastbuy = 99999;
    if(is_array($res) && array_key_exists('best_bid',$res))
    {
        $tickerduop = $res['best_bid'];
		$tickerkongp = $res['best_ask'];
        
    }
    if($d>0)
       echo "ticker duo is $tickerduop  --- ticker kong is $tickerkongp\n";
    return true;
}
function smartAmount($duo)
{
    global $amount;
    global $kamount;
    global $totalduo; 
    global $totalkong;
    global $d;
    global $smart;


    if($duo)
    {
	    return $amount;
    }
    else
    {
	    return $kamount;
    }
}
function kaikong($price)
{
    global $obj;
    global $instrumentId;
    global $kinstrumentId;
    global $kongordersid;
    global $d;
    global $lastKongDone;
    global $lastKongOid;
    $size = smartAmount(0); 
    if($d >0)
    {
        echo "kaikong am:$size, with p: $price\n";
    }
    $res = $obj -> takeOrder("", $kinstrumentId,"2",$price, $size,"0","4","0");
    if(is_array($res) && array_key_exists('order_id',$res))
    {
		$boid = $res['order_id'];
        if($boid != -1)
		{	
			$kongordersid[$boid]= $price;
			if($d>0)
			{
				echo "kaikong order is: $boid";
			}
		}
	else{
			$kongordersid[$boid]=0;

	}
    }
    if($d>1)
        print_r($res);
    return 1;
}
function kaiduo($price)
{
    global $obj;
    global $instrumentId;
    global $ordersid;
    global $d;
    global $lastDuoOid;
    global  $lastDuoDone;
    $size = smartAmount(1); 
    if($d >0)
    {
        echo "kaiduo am:$size, with p: $price\n";
    }
    $res = $obj -> takeOrder("", $instrumentId,"1",$price, $size,"0","4","0");
    if(is_array($res) && array_key_exists('order_id',$res))
    {
		$boid = $res['order_id'];
        if($boid != -1)
	{	
		        //$lastDuoDone = 1;
			//$lastDuoOid = $boid;
			$ordersid[$boid]= $price;
			if($d>0)
			{
				echo "kaiduo order is: $boid";
			}
		}
	else {
		$ordersid[$boid]=0;
	}
    }
    if($d>1)
        print_r($res);
    return 1;
}
function getActiveOrders(&$duop, &$kongp, &$duooid, &$kongoid)
{
   global $ordersid;
   global $kongordersid;
   global $d;
   
   $duop=0;
   
   foreach($ordersid as $key=>$value)
   {
      if($value <= 0)
         continue;
      if($value > $duop)
      {
          $duooid = $key;
          $duop = $value;
          break;
      }
   }
   if($d>0)
   {
       echo "active duo order:$duooid with price:$duop\n";
   }
   $kongp = 0;
   foreach($kongordersid as $key=>$value)
   {
      if($value <= 0)
         continue;
      if($value > $kongp)
      {
          $kongoid = $key;
          $kongp = $value;
          break;
      }
   }
   if($d>0)
   {
       echo "active kong order:$kongoid with price:$kongp\n";
   }
}

function	getKongCnt(&$kongcnt, &$maxkongp)
{
	global $kongpendingsps;
    global $d;
    global $gainRate;
    
    $kongcnt = 0;
    $maxkongp = 0;
    foreach($kongpendingsps as $key=>$value)
    {
        if($value <=0)
            continue;
        $kongcnt ++;
        if($value > $maxkongp)
        {
            $maxkongp = $value;
        }
    }
    if($kongcnt > 0)
    {
	    //$maxkongp = $maxkongp/(1-$gainRate);
    }
    if($d>0)
    {
        echo "kong order cnt: $kongcnt , top kong price: $maxkongp \n";
    }
}
function	getDuoCnt(&$duocnt, &$minduop)
{
	global $pendingsps;
    global $d;
    global $gainRate; 
    $duocnt = 0;
    $minduop = 100000000;
    foreach($pendingsps as $key=>$value)
    {
        if($value <=0)
            continue;
        $duocnt ++;
        if($value < $minduop)
        {
            $minduop = $value;
        }
    }
    if($duocnt > 0)
    {
	    //$minduop = $minduop/(1+$gainRate);
    }
    if($d>0)
    {
        echo "duo order cnt: $duocnt , lowes duo price: $minduop \n";
    }
}

function pingduo($boid, $bamout,$bprice, $oldp)
{
    global $obj;
    global $instrumentId;
    global $d;
    global $pendingsids;
    global $pendingsps;
    global $gainRate; 
    

    $sprice = $bprice;
    if($d)
    {
        echo "kaiduo oid: $boid,  price is: $oldp, pingduo price is:$sprice \n";
    }
    $res = $obj -> takeOrder("", $instrumentId,"3",$sprice, $bamout,"0","4","0");
    if(is_array($res) && array_key_exists('order_id',$res))
    {
      $soid = $res['order_id'];
      if($soid != -1)
      {
        $pendingsids[$soid] = $boid;
        $pendingsps[$soid] = $oldp;
        return $soid;
      }
      else
      {
        echo "ping duo order -1\n";
      }
    }
    else
    {
        print_r($res);
    }
    return -1;
}
function pingkong($boid, $bamout,$bprice, $oldp)
{
    global $obj;
    global $instrumentId;
    global $kinstrumentId;
    global $d;
    global $kongpendingsids;
    global $kongpendingsps;
    global $gainRate; 
    //global $gaintable;
    $sprice = $bprice; 
    if($d)
    {
        echo "kong oid: $boid,  price is: $oldp, pingkong price is:$sprice \n";
    }
    $res = $obj -> takeOrder("", $kinstrumentId,"4",$sprice, $bamout,"0","4","0");
    if(is_array($res) && array_key_exists('order_id',$res))
    {
      $soid = $res['order_id'];
      if($soid != -1)
      {
        $kongpendingsids[$soid] = $boid;
        $kongpendingsps[$soid] = $oldp;
        return $soid;
      }
      else
      {
        echo "ping kong order -1\n";
      }
    }
    else
    {
        print_r($res);
    }
    return -1;
}
function checkBuyOrder($oid, $duo)
{
    global $obj;
    global $instrumentId;
    global $kinstrumentId;
    global $d;
    global $ordersid;
	global $kongordersid;
    global $lastKongDone;
    global $lastDuoDone; 
    global $lastKongOid;
    global $lastDuoOid; 
    global $gainRate;
    global $gainRateAgg;
    global $tickerPrice;
    global $step;
    global $isAgg;

    if($d >0)
    {
        echo "check buy order: $oid\n";
    }
    $oiddata = $oid;
    if($oiddata == "")
        return;
    $instrid = $kinstrumentId;
    if($duo)
    {
    $instrid = $instrumentId;
    }
    $res = $obj -> getOrderInfo($oiddata,$instrid);
    if(is_array($res) && array_key_exists('order_id',$res))
    {
        if($res['state'] == -1)
        {
            //order canceld;
          if($duo)
            $ordersid[$res['order_id']] = 0;
          else
            $kongordersid[$res['order_id']] = 0;
        }
        else if($res['state'] == 2)
        {
            //order done
          if($duo)
            {
              $ordersid[$res['order_id']] = 0;
              $newp = $res['price_avg']*(1+$gainRate);
              if($isAgg)
                  $newp = $res['price_avg']*(1+$gainRateAgg);
              $lastDuoOid = pingduo($res['order_id'],$res['size'],$newp, $res['price_avg']); 
              //$lastDuoDone = 2;
            }
            else
            {
              $newp = $res['price_avg']*(1-$gainRate);
              $kongordersid[$res['order_id']] = 0;
              $lastKongOid=pingkong($res['order_id'],$res['size'],$newp, $res['price_avg']); 
              //$lastKongDone = 2;
            }
        }
	else
	{
	   $p = $res['price'];
	   $oid = $res['order_id'];
     if($duo)
	   {
        if($p >0 && $p*(1+$step) < $tickerPrice)
        {
          cancelOrder($oid, $duo);
        }
	   }
	   else
	   {
		   if($p>0 && $p*(1-$step) >$tickerPrice)
		   {
			   cancelOrder($oid, $duo);
		   }
	   }
	}
            
    }
    if($d>1)
        print_r($res);
}
function checkSellOrder( $oid, $boid, $duo)
{
    global $obj;
    global $instrumentId;
    global $kinstrumentId;
    global $d;
    global $ordersid;
    global $pendingsids;
    global $pendingsps;
    global $kongordersid;
    global $kongpendingsids;
    global $kongpendingsps;	
    global $lastDuoDone;
    global $lastKongDone;
    global $sdcnt;
    global $tickerPrice;
    global $cancelOrderRate;
    global $cancelOids;
    global $totalduo; 
    global $totalkong;
    global $qpk;
    global $qpd;
    global $gainRate;
    if($d>0)
    {
        echo "check sell order:$oid, buy order is:$boid\n";
    }
    $oiddata = $oid;
    if($oiddata == "")
        return;
    $instrid = $kinstrumentId;
    if($duo)
    {
    $instrid = $instrumentId;
    }
    $res = $obj -> getOrderInfo($oiddata, $instrid);
    if(is_array($res) && array_key_exists('state', $res) && array_key_exists('order_id',$res))
    {
        if($res['state'] == -1)
        {
            //sell cancel;
          $orderid = $res['order_id'];
          $size = $res['size'];
          if($duo){
            $pendingsids[$orderid] = 0;
            $pendingsps[$orderid] = 0;
            $ordersid[$boid]=0;
            $lastDuoDone = 0;
          }
          else
          {
              $kongpendingsids[$orderid]= 0;
              $kongpendingsps[$orderid]= 0;
              $kongordersid[$boid]=0;	
              $lastKongDone = 0;	    
          }
          $cancelOids[$orderid]= 0;
        }
        else if($res['state'] == 2)
        {
            //sell done;
          $sdcnt ++;
          if($duo){
                $pendingsids[$res['order_id']] = 0;
                $pendingsps[$res['order_id']] = 0;
                echo "duo sell done $sdcnt \n";
                $lastDuoDone=0;
           }
          else
          {
                $kongpendingsids[$res['order_id']] = 0;
                $kongpendingsps[$res['order_id']] = 0;
                echo "kong sell done $sdcnt \n";				
                $lastKongDone = 0;
          }
        }
        else
        {

          $size = $res['size'];
          if($duo)
          {
             $totalduo += $size;
             $refp = $pendingsps[$res['order_id']];
             if($refp > 0 && $tickerPrice < ($refp * (1-$gainRate-$cancelOrderRate)))
             {
               $cancelOids[$res['order_id']] = 1;
             }
          }
          else
          {
             $totalkong += $size;
             $refp = $kongpendingsps[$res['order_id']];
             if($refp >0 && $tickerPrice > ($refp * (1+$cancelOrderRate)))
             {
                 $cancelOids[$res['order_id']] = 1;
             }

          }       
        }
            
    }
    else if(is_array($res)  && array_key_exists('code',$res) && $res['code']==35029)
    {
      if($duo)
        $ordersid[$boid]=0;
      else
        $kongordersid[$boid]=0;
    }
    else
      print_r($res);
}
function cancelOrder($oid, $duo=1)
{
    global $obj;
    global $instrumentId;
    global $kinstrumentId;
    global $d;
    $instrid = $kinstrumentId;
    if($duo)
    {
    $instrid = $instrumentId;
    }
    $res = $obj -> revokeOrder($instrid,$oid);
    if($d>1)
    {
        print_r($res);
    }
}
function printArray(&$arr, $tag)
{
    echo "print arr: $tag\n";
    foreach($arr as $key=>$value)
    {
        echo "key is:$key, value is:$value\n";
    }
}
function syncArray(&$sids, &$sps)
{
   foreach($sids as $key=>$value)
   {
      if($value <= 0)
      {
          $sps[$key] = 0;
      }
   }
}
function cleanEmptyArray(&$arr)
{
    $hole = false;
    foreach($arr as $key=>$value)
    {
        if($value <= 0)
        {
            $hole = true;
        }
    }
    if($hole)
    {
        array_unique($arr);
        arsort($arr);
        array_pop($arr);
    }
}
function realAB($cnt,$tickerprice)
{
	global $obj;
	global $instrumentId;
	$res = $obj->getSpecificPosition($instrumentId);

        $duoamt = 0;
        $kongamt = 0;
	if($res)
	{
     $avalp = 'avail_position';
	   for($i=0;$i<count($res['holding']);$i++)
	   {
		   if($res['holding'][$i]['side'] == 'short')
		   {
			   $kongamt = $res['holding'][$i][$avalp];
		   } 
		   else if($res['holding'][$i]['side'] == 'long')
		   {
			   $duoamt = $res['holding'][$i][$avalp];
		   } 
	   }
	}
	print('real balance '+ $cnt + ' tick price:'+$tickerprice + '\n');  
   //ping duo;
	if($duoamt > 0)
		pingduo('', $duoamt, $tickerprice-0.1,$tickerprice);
	if($kongamt > 0)
		pingkong('', $kongamt, $tickerprice+0.1, $tickerprice);
   //ping kong;
}
function autoBalance($cnt)
{
    global $obj;
    global $pendingsps;
    global $kongpendingsps;
    global $pendingsids;
    global $kongpendingsids;
    global $cancelOids;
    global $d;
    global $abedCnt;

   $ccnt = $cnt;
   asort($pendingsids);//from big to small
   foreach($pendingsids as $key=>$value)
   {
	   if($key <= 0)
		 continue; 
	   if($d)
	       print("Auto banlance,cancel duo $key \n");
	   cancelOrder($key);
	   sleep(100);
	   $pendingsids[$key] =0;
	   $pendingsps[$key] =0;
	   $ccnt --;
	   if($ccnt == 0)
		break;
   }
   $ccnt = $cnt;
   asort($kongpendingsids);//small to big
   foreach($kongpendingsids as $key=>$value)
   {
	   if($key <= 0)
		 continue; 
	   if($d)
	       print("Auto banlance,cancel kong $key \n");
	   cancelOrder($key);
	   sleep(100);
            $kongpendingsids[$key]= 0;
            $kongpendingsps[$key]= 0;
	   $ccnt --;
	   if($ccnt == 0)
		break;
   }
   $abedCnt = $cnt;
   print('Auto blanced ' + $abedCnt + '\n');
}
function checkOrders()
{
    global $ordersid;
    global $kongordersid;
    global $obj;
    global $pendingsids;
    global $kongpendingsids;
    global $pendingsps;
    global $kongpendingsps;
    global $cancelOids;
    global $d;
    global $updatesellorders;

   foreach($ordersid as $key=>$value)
   {
      if($value <= 0)
         continue;
      $oiddata = $key;
      checkBuyOrder($key,true);
      usleep(500);
   }
   foreach($kongordersid as $key=>$value)
   {
      if($value <= 0)
         continue;
      $oiddata = $key;
      checkBuyOrder($key,false);
      usleep(500);
   }
   if($updatesellorders)
   { 
       $cnt = $updatesellorders;
       arsort($pendingsids);
       foreach($pendingsids as $key=>$value)
       {
           if($value <= 0)
             continue;
           $cnt --;
           checkSellOrder($key, $value,true);
           usleep(500);
           if($cnt <= 0)
          break;
       }
       $cnt = $updatesellorders; 
       arsort($kongpendingsids);
       foreach($kongpendingsids as $key=>$value)
       {
           if($value <= 0)
               continue;
           $cnt --;
           checkSellOrder($key, $value,false);
           usleep(500);
           if($cnt <= 0)
          break;
       }
   } 
   foreach($cancelOids as $key=>$value)
   {
       if($value <= 0)
           continue;
       cancelOrder($key);
       usleep(100);
   }

}


while(true)
{
try {
        include 'swap-c.php';
		$kongp = 0;
		$duop = 0;
		$minduop = 0;
		$maxkongp = 0;
		$lastkongp = 0;
		$lastduop =0;
		$tickerduop = 0;
		$tickerkongp = 0;
		sleep($interval);
		//$ksecs = 3600;
		getTickerPrice($tickerduop, $tickerkongp);
		getDuoCnt($duocnt, $minduop);
		getKongCnt($kongcnt, $maxkongp);
		$tickerPrice = $tickerduop;
		$kongoid='';
		$duooid='';
		$tick='';
		$m60 = 0;
		$mid = 0;
		$refh =0;
		if($abedCnt > 0)
		{
	           realAB($abedCnt,$tickerPrice);
		}
		if(($duocnt > $ablimit && $kongcnt > $ablimit))
		{
		   //auto balance;
	           autoBalance($abcnt);
		}
		else
		   $abedCnt = 0;
		$refp= getRefPrice($ksecs,$tick, $m60,$mid);
		if($fixdir == 0)
		  {	
	            $refh = $refp;
		  }
		if($duocnt > $maxorders || $kongcnt > $maxorders)
		    $refh = 0;
		getActiveOrders($duop, $kongp, $duooid, $kongoid);
		if($mode == 1)
		{
                   if($refp == 2)
			$m60 = $top;
		   else if($refp == 1)
		        $m60 = $bottom;
		}
		if($d)
		{
			echo "mid is $mid, m60 is $m60 ,ticker price is: $tickerPrice \n";
			echo "refh is :$refh, cross is:$refp, aggxive is: $isAgg, cache is:$kdjcache ,for tick:$tick \n";
			echo "maxkongp:$maxkongp, minduop:$minduop ,duo:$duocnt ,kong :$kongcnt \n";
			echo "ref duo is $duo, ref kong is $kong \n";
		
		}
		if($refp != $kdjcache && $refp == $refh)
		{ 
		    if($refp == 1 && $m60 < $tickerPrice )
		    {
			//kaikong
			if($maxkongp == 0 || ($maxkongp*(1+$step) < $tickerkongp))
			{
				if($kongp == 0)
				{
				     	kaikong($tickerkongp - $limit);
				}
			}

		    }
		    else if($refp == 2 && $m60 > $tickerPrice && $tickerPrice < $top)
		    {
			if($minduop == 100000000 || ($minduop*(1-$step) > $tickerduop))
			{
				if($duop ==0)
				{
				   kaiduo($tickerduop + $limit);
				}
			}
		    }
		}
		$kdjcache = $refp;
				
        $totalduo =0;
        $totalkong=0;
        checkOrders();
        $tick ++;
        
        cleanEmptyArray($ordersid);
        cleanEmptyArray($pendingsids);
        cleanEmptyArray($pendingsps);
        cleanEmptyArray($kongordersid);
        cleanEmptyArray($kongpendingsids);
        cleanEmptyArray($kongpendingsps);
        cleanEmptyArray($cancelOids);
        syncArray($pendingsids, $pendingsps);
        syncArray($kongpendingsids, $kongpendingsps);
        if($d>0)
        {
            echo "tick is $tick\n";
            printArray($ordersid, "kduo orders");
            printArray($pendingsids, "pduo orders");
            printArray($pendingsps, "kduo prices");
			
			printArray($kongordersid, "kkong orders");
            printArray($kongpendingsids, "pkong orders");
            printArray($kongpendingsps, "kkong prices");
        }

} catch (Exception $e) {
        $msg = $e -> getMessage();
        error_log($msg);
}



}

?>


