<?php
// Серверный порт GeocodingService: DaData + Nominatim, reverse geocode, журнал API.
declare(strict_types=1);

final class GeocodingService
{
    private const DADATA_SUGGEST = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';
    private const DADATA_GEOLOCATE = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/geolocate/address';
    private const NOMINATIM_SEARCH = 'https://nominatim.openstreetmap.org/search';
    private const NOMINATIM_REVERSE = 'https://nominatim.openstreetmap.org/reverse';

    public static function search(\PDO $db, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) return [];
        $svc = ServiceSettings::get($db);
        $city = (string) $svc['city_name'];
        $region = (string) $svc['region_name'];
        $results = [];
        if (DADATA_API_KEY !== '') {
            $body = json_encode([
                'query'=>$query,'count'=>5,
                'locations'=>[['region'=>$region,'city'=>$city],['region'=>$region]],
                'restrict_value'=>false,
            ], JSON_UNESCAPED_UNICODE);
            [$code,$raw,$ms]=self::request(self::DADATA_SUGGEST,'POST',$body,[
                'Authorization: Token '.DADATA_API_KEY,'Content-Type: application/json','Accept: application/json'
            ]);
            $json=json_decode($raw,true);
            if($code>=200&&$code<300&&is_array($json)){
                foreach($json['suggestions']??[] as $s){$lat=(float)($s['data']['geo_lat']??0);$lng=(float)($s['data']['geo_lon']??0);if($lat&&$lng)$results[]=['displayName'=>$s['value']??'','fullAddress'=>$s['unrestricted_value']??$s['value']??'','latitude'=>$lat,'longitude'=>$lng,'source'=>'dadata'];}
            }
            self::log($db,'dadata','suggest',$query,$code>=200&&$code<300?'success':'failed',$code,$raw,$ms);
        }
        if(count($results)<3){
            $cityLower=mb_strtolower($city);
            $regionLower=mb_strtolower($region);
            $searchQuery=(str_contains(mb_strtolower($query),$cityLower)||str_contains(mb_strtolower($query),$regionLower))
                ? $query : $query.', '.$region;
            $latN=$svc['center_latitude']+0.9;$latS=$svc['center_latitude']-0.9;
            $lngW=$svc['center_longitude']-1.1;$lngE=$svc['center_longitude']+1.1;
            $url=self::NOMINATIM_SEARCH.'?'.http_build_query(['q'=>$searchQuery,'format'=>'json','addressdetails'=>1,'limit'=>7,'countrycodes'=>'ru','accept-language'=>'ru','viewbox'=>sprintf('%.4f,%.4f,%.4f,%.4f',$lngW,$latN,$lngE,$latS),'bounded'=>1]);
            [$code,$raw,$ms]=self::request($url,'GET',null,['User-Agent: TaxiTyumen/1.0','Accept-Language: ru']);
            $json=json_decode($raw,true);
            if($code>=200&&$code<300&&is_array($json))foreach($json as $r){$lat=(float)($r['lat']??0);$lng=(float)($r['lon']??0);if(!$lat||!$lng)continue;$duplicate=false;foreach($results as $x)if(abs($x['latitude']-$lat)<.001&&abs($x['longitude']-$lng)<.001){$duplicate=true;break;}if(!$duplicate)$results[]=['displayName'=>$r['display_name']??'','fullAddress'=>$r['display_name']??'','latitude'=>$lat,'longitude'=>$lng,'source'=>'nominatim'];}
            self::log($db,'nominatim','search',$query,$code>=200&&$code<300?'success':'failed',$code,$raw,$ms);
        }
        return array_slice($results,0,7);
    }

    public static function reverse(\PDO $db,float $lat,float $lng): array
    {
        if(DADATA_API_KEY!==''){
            $body=json_encode(['lat'=>$lat,'lon'=>$lng,'radius_meters'=>100,'count'=>1]);
            [$code,$raw,$ms]=self::request(self::DADATA_GEOLOCATE,'POST',$body,['Authorization: Token '.DADATA_API_KEY,'Content-Type: application/json']);
            $json=json_decode($raw,true);$s=$json['suggestions'][0]??null;
            self::log($db,'dadata','reverse',"$lat,$lng",$s?'success':'failed',$code,$raw,$ms);
            if($s)return['displayName'=>$s['value']??'','fullAddress'=>$s['unrestricted_value']??$s['value']??'','latitude'=>$lat,'longitude'=>$lng,'source'=>'dadata'];
        }
        $url=self::NOMINATIM_REVERSE.'?'.http_build_query(['lat'=>$lat,'lon'=>$lng,'format'=>'json','addressdetails'=>1,'accept-language'=>'ru']);
        [$code,$raw,$ms]=self::request($url,'GET',null,['User-Agent: TaxiTyumen/1.0','Accept-Language: ru']);
        $json=json_decode($raw,true);self::log($db,'nominatim','reverse',"$lat,$lng",is_array($json)?'success':'failed',$code,$raw,$ms);
        return['displayName'=>$json['display_name']??sprintf('%.4f, %.4f',$lat,$lng),'fullAddress'=>$json['display_name']??'','latitude'=>$lat,'longitude'=>$lng,'source'=>'nominatim'];
    }

    public static function check(\PDO $db): array
    {
        $svc=ServiceSettings::get($db);
        $items=self::search($db,(string)$svc['city_name']);
        return['configured'=>DADATA_API_KEY!=='','ok'=>count($items)>0,'results'=>count($items),'sources'=>array_values(array_unique(array_column($items,'source'))),'message'=>count($items)>0?'Геокодинг доступен':'DaData/Nominatim недоступны'];
    }

    private static function request(string $url,string $method,?string $body,array $headers):array
    {$s=microtime(true);$ctx=stream_context_create(['http'=>['timeout'=>8,'method'=>$method,'ignore_errors'=>true,'header'=>implode("\r\n",$headers)."\r\n",'content'=>$body??'']]);$raw=@file_get_contents($url,false,$ctx);$code=0;foreach($http_response_header??[] as $h)if(preg_match('/^HTTP\/\S+\s+(\d{3})/',$h,$m))$code=(int)$m[1];return[$code,$raw!==false?$raw:'Ошибка соединения',(int)round((microtime(true)-$s)*1000)];}
    private static function log(\PDO $db,string $service,string $action,string $summary,string $status,int $code,string $raw,int $ms):void
    {try{$db->prepare('INSERT INTO service_call_logs(service,action,request_summary,status,http_code,response_body,duration_ms) VALUES (?,?,?,?,?,?,?)')->execute([$service,$action,mb_substr($summary,0,500),$status,$code,mb_substr($raw,0,5000),$ms]);}catch(\Throwable){}}
}
