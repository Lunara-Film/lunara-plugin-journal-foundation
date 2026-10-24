<?php
/** Behavioral delivery tests. No network, paid generation, or production writes. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code, $message = '', $data = array() ) { $this->code=$code; $this->message=$message; $this->data=$data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Request implements ArrayAccess {
    private $params=array(); private $body=array();
    public function __construct( $method='GET', $route='' ) {}
    public function set_param( $key, $value ) { $this->params[$key]=$value; }
    public function get_param( $key ) { return $this->params[$key]??null; }
    public function set_header( $key, $value ) {}
    public function set_body( $value ) { $this->body=json_decode($value,true); }
    public function get_json_params() { return $this->body; }
    public function offsetExists($key):bool { return isset($this->params[$key]); }
    #[\ReturnTypeWillChange]
    public function offsetGet($key) { return $this->params[$key]??null; }
    public function offsetSet($key,$value):void { $this->params[$key]=$value; }
    public function offsetUnset($key):void { unset($this->params[$key]); }
}
class WP_REST_Response {
    private $data;
    public function __construct($data){$this->data=$data;}
    public function get_data(){return $this->data;}
    public function header($key,$value){}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function rest_ensure_response($v){return $v instanceof WP_REST_Response?$v:new WP_REST_Response($v);}
function absint($v){return abs((int)$v);}
function wp_json_encode($v){return json_encode($v);}
function wp_timezone(){return new DateTimeZone('America/Chicago');}
function get_current_user_id(){return 7;}
function user_can($id,$cap,$post=0){return !empty($GLOBALS['can_publish']);}
function current_user_can($cap){return !empty($GLOBALS['can_publish']);}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;return true;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$v){$GLOBALS['meta'][$id][$key]=$v;return true;}
function delete_post_meta($id,$key){unset($GLOBALS['meta'][$id][$key]);return true;}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function clean_post_cache($id){}
function get_post_status($id){return is_object($id)?$id->post_status:get_post($id)->post_status;}
function wp_next_scheduled($hook){return $GLOBALS['events'][$hook]??false;}
function wp_schedule_event($at,$interval,$hook){$GLOBALS['events'][$hook]=$at;return true;}
function wp_schedule_single_event($at,$hook,$args,$error=false){
    $GLOBALS['schedule_calls']++;
    if(isset($GLOBALS['events'][$hook])&&abs($GLOBALS['events'][$hook]-$at)<600)return new WP_Error('duplicate_event','Duplicate event');
    if(!$GLOBALS['schedule_ok'])return false;
    $GLOBALS['events'][$hook]=$at;return true;
}
function get_posts($args){
    if(!empty($args['date_query']))return $GLOBALS['published_today']?array(99):array();
    return array_values(array_filter($GLOBALS['posts'],static function($p){return in_array($p->post_status,array('draft','pending','private'),true)&&get_post_meta($p->ID,Lunara_Journal_Publishing::META,true);}));
}
class Lunara_Journal_Control_Plane { public static function get_active_config(){return array('chatgpt'=>array('may_publish'=>$GLOBALS['enabled']));} }
class Lunara_Journal_Validator { public static function validate_post($id,$config){return array('valid'=>$GLOBALS['valid']);} }
class Lunara_Journal_Foundation { public static function record_bridge_log_entry($id,$action,$data){} }
class Lunara_Journal_Desk_API {
    public static function settings_permissions_check($r){return $GLOBALS['session']?true:new WP_Error('session','Session required');}
    public static function publish_permissions_check($r){return $GLOBALS['session']&&$GLOBALS['can_publish']?true:new WP_Error('permission','Publication access required');}
    public static function with_post_lock($id,$callback){return $callback();}
    public static function with_delivery_lock($callback){return $GLOBALS['locked']?new WP_Error('busy','Busy'):$callback();}
    public static function revision_for_post($id){return hash('sha256',get_post($id)->post_title);}
    public static function check_draft_revision($r){$p=get_post($r['id']);$b=$r->get_json_params();return $p&&($b['expected_revision']??'')===self::revision_for_post($p->ID)?$p:new WP_Error('conflict','Draft changed');}
}
class Lunara_Journal_Fast_Desk {
    public static function rest_publish_draft($r){
        $GLOBALS['publish_attempts']++;
        if(!$GLOBALS['enabled']||!$GLOBALS['valid'])return new WP_Error('validation','Cannot publish');
        $p=get_post($r['id']);$p->post_status='publish';$GLOBALS['published_today']=true;
        return new WP_REST_Response(array('published'=>true,'id'=>$p->ID));
    }
}
require dirname(__DIR__).'/includes/class-lunara-journal-publishing.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);$GLOBALS['checks']++;}
function reset_delivery(){
    $GLOBALS['posts']=array(42=>(object)array('ID'=>42,'post_type'=>'journal','post_status'=>'draft','post_title'=>'Reviewed feature story'));
    $GLOBALS['meta']=array();$GLOBALS['options']=array();$GLOBALS['events']=array();$GLOBALS['checks']=$GLOBALS['checks']??0;
    foreach(array('can_publish','enabled','valid','session','schedule_ok') as $k)$GLOBALS[$k]=true;
    $GLOBALS['locked']=false;$GLOBALS['published_today']=false;$GLOBALS['publish_attempts']=0;$GLOBALS['schedule_calls']=0;
}
function request_delivery($extra=array()){$r=new WP_REST_Request('POST');$r->set_param('id',42);$r->set_body(json_encode(array_merge(array('expected_revision'=>Lunara_Journal_Desk_API::revision_for_post(42)),$extra)));return $r;}
reset_delivery();
check(is_wp_error(Lunara_Journal_Publishing::parse_publish_at('2027-02-30T10:00:00Z')),'impossible date rejected');
check(is_wp_error(Lunara_Journal_Publishing::parse_publish_at(gmdate('Y-m-d\TH:i:s',time()+3600))),'timezone required');
check(is_wp_error(Lunara_Journal_Publishing::parse_publish_at(gmdate('c',time()-10))),'past dates rejected');
check(!is_wp_error(Lunara_Journal_Publishing::parse_publish_at(gmdate('c',time()+3600))),'future ISO date accepted');
check(!is_wp_error(Lunara_Journal_Publishing::parse_publish_at(gmdate('Y-m-d\TH:i:s',time()+3600).'.000Z')),'JavaScript toISOString millisecond timestamp accepted');
check(is_wp_error(Lunara_Journal_Publishing::rest_reserve(request_delivery())),'approval cannot be implicit');
$GLOBALS['session']=false;
check(is_wp_error(Lunara_Journal_Publishing::rest_reserve(request_delivery(array('approved'=>true)))),'session required even direct handler call');
$GLOBALS['session']=true;$GLOBALS['enabled']=false;
check(is_wp_error(Lunara_Journal_Publishing::rest_reserve(request_delivery(array('approved'=>true)))),'configuration gate enforced');
$GLOBALS['enabled']=true;$GLOBALS['valid']=false;
check(is_wp_error(Lunara_Journal_Publishing::rest_reserve(request_delivery(array('approved'=>true)))),'invalid image/article cannot enter reserve');
$GLOBALS['valid']=true;
$saved=Lunara_Journal_Publishing::rest_reserve(request_delivery(array('approved'=>true,'evergreen'=>true)))->get_data();
check($saved['reserved']&&get_post_status(42)==='draft','reserve approval never immediately publishes');
check(isset($GLOBALS['events'][Lunara_Journal_Publishing::HOOK]),'approved reserve schedules its worker');
$approval=get_post_meta(42,Lunara_Journal_Publishing::META,true);
check($approval['expires']>time()+29*DAY_IN_SECONDS,'explicit evergreen approval lasts 30 days');
$GLOBALS['posts'][42]->post_title='Edited after approval';
check((bool)Lunara_Journal_Publishing::approval_problem(get_post(42),$approval),'edits invalidate prior approval');
$GLOBALS['posts'][42]->post_title='Reviewed feature story';$GLOBALS['can_publish']=false;
check((bool)Lunara_Journal_Publishing::approval_problem(get_post(42),$approval),'revoked publisher cannot deliver');
$GLOBALS['can_publish']=true;$approval['expires']=time()-1;
check((bool)Lunara_Journal_Publishing::approval_problem(get_post(42),$approval),'expired approvals require review');
reset_delivery();
$bad=Lunara_Journal_Publishing::rest_schedule(request_delivery(array('publish_at'=>gmdate('c',time()+2*DAY_IN_SECONDS))));
check(is_wp_error($bad),'news stories cannot be scheduled two days away');
$scheduled=Lunara_Journal_Publishing::rest_schedule(request_delivery(array('publish_at'=>gmdate('c',time()+3600))))->get_data();
check($scheduled['scheduled']&&get_post_status(42)==='draft','timed delivery keeps article in guarded draft state');
$same=Lunara_Journal_Publishing::rest_schedule(request_delivery(array('publish_at'=>$scheduled['publish_at'])))->get_data();
check($same['scheduled']&&$GLOBALS['schedule_calls']===1,'schedule retry reuses confirmed approval without duplicate cron');
$previous=get_post_meta(42,Lunara_Journal_Publishing::META,true);
$failed_change=Lunara_Journal_Publishing::rest_schedule(request_delivery(array('publish_at'=>gmdate('c',strtotime($scheduled['publish_at'])+120))));
check(is_wp_error($failed_change)&&get_post_meta(42,Lunara_Journal_Publishing::META,true)===$previous,'failed reschedule preserves the previous working approval');
Lunara_Journal_Publishing::tick();
check($GLOBALS['publish_attempts']===0,'future article is not delivered early');
$approval=get_post_meta(42,Lunara_Journal_Publishing::META,true);$approval['publish_at']=time()-1;update_post_meta(42,Lunara_Journal_Publishing::META,$approval);
$GLOBALS['valid']=false;Lunara_Journal_Publishing::tick();
check(get_post_status(42)==='draft','delivery revalidates at due time');
$GLOBALS['valid']=true;Lunara_Journal_Publishing::tick();
check(get_post_status(42)==='publish','due approved validated article publishes');
check(get_post_meta(42,Lunara_Journal_Publishing::META,true)==='','successful delivery consumes its approval');
$attempts=$GLOBALS['publish_attempts'];Lunara_Journal_Publishing::tick();
check($GLOBALS['publish_attempts']===$attempts,'repeat worker cannot duplicate publication');
reset_delivery();$GLOBALS['schedule_ok']=false;
check(is_wp_error(Lunara_Journal_Publishing::rest_schedule(request_delivery(array('publish_at'=>gmdate('c',time()+3600))))),'failed scheduling is reported');
check(get_post_meta(42,Lunara_Journal_Publishing::META,true)==='','failed schedule does not leave phantom approved delivery');
reset_delivery();Lunara_Journal_Publishing::rest_reserve(request_delivery(array('approved'=>true)));
Lunara_Journal_Publishing::rest_reserve(request_delivery(array('approved'=>false)));
check(get_post_meta(42,Lunara_Journal_Publishing::META,true)==='','editor can revoke approval');
reset_delivery();$GLOBALS['locked']=true;Lunara_Journal_Publishing::tick();
check($GLOBALS['publish_attempts']===0,'another worker holds delivery lock');
$now=new DateTimeImmutable('2026-10-23T09:00:00-05:00');
$news=array('expires_at'=>'2026-10-24T08:00:00-05:00');
check(Lunara_Journal_Publishing::coverage_days($now,0,array($news,$news),array())===1,'tomorrow-expired news is not counted as another covered day');
$evergreen=array('expires_at'=>'2026-11-20T08:00:00-06:00');
check(Lunara_Journal_Publishing::coverage_days($now,1,array($evergreen,$evergreen),array())===3,'today published plus two reserve articles covers three consecutive days');
check(Lunara_Journal_Publishing::coverage_days($now,0,array(),array(array('publish_at'=>'2026-10-24T15:00:00Z')))===0,'a future scheduled post does not hide a gap today');
echo $GLOBALS['checks']." publishing behavior checks passed\n";
