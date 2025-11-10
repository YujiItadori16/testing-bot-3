<?php
// ======================
// Telegram PHP Bot — Channel Gate + Referrals + Leaderboard
// Works on Render.com Docker Web Service (webhook-based)
// ======================

// --- Config (env-first, with safe fallbacks) ---
$BOT_TOKEN = getenv('BOT_TOKEN') ?: '8401609959:AAFGmYh29uJM-JJNUMJc0ByKVfDfQSlILMc';
$API      = "https://api.telegram.org/bot{$BOT_TOKEN}";

// Channels to check (bot must be able to call getChatMember; usually requires being admin of the channel)
$CHANNEL_1 = getenv('CHANNEL_1') ?: '@bigbumpersaleoffers';
$CHANNEL_2 = getenv('CHANNEL_2') ?: '@backupchannelbum';

// Admins (comma-separated user IDs)
$ADMIN_IDS = array_filter(array_map('trim', explode(',', getenv('ADMIN_IDS') ?: '1702919355')));

// Files
$DATA_FILE = __DIR__ . '/users.json';
$ERROR_LOG = __DIR__ . '/error.log';

// Hard links for the first screen (buttons open channels)
$CHANNEL_1_LINK = 'https://t.me/bigbumpersaleoffers';
$CHANNEL_2_LINK = 'https://t.me/backupchannelbum';

// --- Helpers ---
function log_error($msg){
  global $ERROR_LOG;
  $line = '['.date('c')."] ".$msg."\n";
  @file_put_contents($ERROR_LOG, $line, FILE_APPEND);
}

function tg($method, $params = []){
  global $API;
  $ch = curl_init($API . '/' . $method);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($params)
  ]);
  $res = curl_exec($ch);
  if($res === false){
    log_error('curl_error: '.curl_error($ch));
    curl_close($ch);
    return false;
  }
  curl_close($ch);
  return json_decode($res, true);
}

function load_data(){
  global $DATA_FILE;
  if(!file_exists($DATA_FILE)){
    $init = ["users"=>[],"referrals"=>[],"meta"=>["created"=>date('c')]];
    file_put_contents($DATA_FILE, json_encode($init, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  }
  $fp = fopen($DATA_FILE, 'r');
  if(!$fp) return ["users"=>[],"referrals"=>[],"meta"=>[]];
  flock($fp, LOCK_SH);
  $raw = stream_get_contents($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  $data = json_decode($raw, true);
  return is_array($data) ? $data : ["users"=>[],"referrals"=>[],"meta"=>[]];
}

function save_data($data){
  global $DATA_FILE;
  $fp = fopen($DATA_FILE, 'c+');
  if(!$fp){ log_error('Failed to open data file for writing'); return; }
  flock($fp, LOCK_EX);
  ftruncate($fp, 0);
  fwrite($fp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
}

function is_admin($uid){
  global $ADMIN_IDS; return in_array((string)$uid, array_map('strval', $ADMIN_IDS), true);
}

function kb($rows){ return ["inline_keyboard"=>$rows]; }

function channel_gate_keyboard(){
  global $CHANNEL_1_LINK, $CHANNEL_2_LINK;
  return kb([
    [ ["text"=>"Channel 1", "url"=>$CHANNEL_1_LINK], ["text"=>"Channel 2", "url"=>$CHANNEL_2_LINK] ],
    [ ["text"=>"Try Again", "callback_data"=>"try_again"] ]
  ]);
}

function invite_keyboard($share_url){
  return kb([
    [ ["text"=>"Invite", "callback_data"=>"invite"] ],
    [ ["text"=>"Forward", "url"=>$share_url] ]
  ]);
}

function get_username_or_name($u){
  if(!$u) return '';
  if(isset($u['username']) && $u['username']) return '@'.$u['username'];
  $first = $u['first_name'] ?? '';
  $last = $u['last_name'] ?? '';
  return trim($first.' '.$last);
}

function check_joined($user_id){
  global $CHANNEL_1, $CHANNEL_2;
  $ok1 = false; $ok2 = false;
  foreach ([1=>$CHANNEL_1, 2=>$CHANNEL_2] as $i=>$ch){
    $res = tg('getChatMember', ['chat_id'=>$ch, 'user_id'=>$user_id]);
    if(isset($res['ok']) && $res['ok']){
      $st = $res['result']['status'] ?? '';
      if(in_array($st, ['member','administrator','creator'])){ if($i===1) $ok1=true; else $ok2=true; }
    }
  }
  return $ok1 && $ok2;
}

function bot_username(){
  static $un = null; if($un!==null) return $un;
  $me = tg('getMe');
  $un = $me['ok'] ? $me['result']['username'] : '';
  return $un;
}

function personal_link($uid){
  $u = bot_username();
  if($u){
    return "https://t.me/{$u}?start=".$uid;
  }
  // Fallback (ask user to replace <your_bot_username>)
  return "https://t.me/<your_bot_username>?start=".$uid;
}

function ensure_user(&$data, $uid){
  if(!isset($data['users'][$uid])){
    $data['users'][$uid] = [
      'id'=>$uid,
      'count'=>0,
      'invitees'=>[],
      'joined_ok'=>false,
      'name'=>'',
      'username'=>''
    ];
  }
}

function handle_referral_on_start(&$data, $new_user_id, $start_payload){
  $ref = trim($start_payload);
  if($ref === '' || !ctype_digit($ref)) return;
  if($ref === strval($new_user_id)) return; // self-ref ignored

  ensure_user($data, $ref);
  ensure_user($data, $new_user_id);

  // Only count once per referred user
  if(!in_array($new_user_id, $data['users'][$ref]['invitees'], true)){
    $data['users'][$ref]['invitees'][] = $new_user_id;
    $data['users'][$ref]['count'] = count($data['users'][$ref]['invitees']);
  }
}

function send_start_screen($chat_id){
  $text = "First join both channels to move to the next step.";
  tg('sendMessage', [
    'chat_id'=>$chat_id,
    'text'=>$text,
    'reply_markup'=>channel_gate_keyboard(),
    'disable_web_page_preview'=>true
  ]);
}

function send_invite_prompt($chat_id){
  $text = "To get YouTube premium of 1 month for free. First invite 5 people.";
  tg('sendMessage', [
    'chat_id'=>$chat_id,
    'text'=>$text,
    'reply_markup'=>kb([[ ["text"=>"Invite", "callback_data"=>"invite"] ]]),
    'disable_web_page_preview'=>true
  ]);
}

function make_share_text($uid){
  $line1 = "We are giving YouTube Premium to everyone for 1 month so come and grab the offer";
  $line2 = "My referral ID: ".$uid;
  $link = personal_link($uid);
  $full = $line1."\n\n".$line2."\n\nJoin via my link: ".$link;
  return $full;
}

function share_url($text){
  return 'https://t.me/share/url?url=' . rawurlencode($text);
}

function handle_commands($msg, &$data){
  $chat_id = $msg['chat']['id'];
  $uid = $msg['from']['id'];
  $text = trim($msg['text'] ?? '');

  // Store user meta
  ensure_user($data, $uid);
  $data['users'][$uid]['name'] = get_username_or_name($msg['from']);
  $data['users'][$uid]['username'] = $msg['from']['username'] ?? '';

  if(str_starts_with($text, '/start')){
    // referral param
    $parts = explode(' ', $text, 2);
    $payload = $parts[1] ?? '';
    handle_referral_on_start($data, $uid, $payload);
    save_data($data);
    send_start_screen($chat_id);
    return;
  }

  if($text === '/mylink'){
    $link = personal_link($uid);
    tg('sendMessage', [
      'chat_id'=>$chat_id,
      'text'=>"Your personal invite link:\n".$link,
      'disable_web_page_preview'=>true
    ]);
    return;
  }

  if($text === '/mystats'){
    $c = $data['users'][$uid]['count'] ?? 0;
    $need = max(0, 5 - $c);
    tg('sendMessage', [
      'chat_id'=>$chat_id,
      'text'=>"You have invited {$c} unique user(s)." . ($need>0?"\nInvite {$need} more to reach 5.":"\n✅ Requirement met!"),
    ]);
    return;
  }

  // Admin-only commands
  if(is_admin($uid)){
    if(preg_match('~^/stats\s+(\d+)~', $text, $m)){
      $who = $m[1]; ensure_user($data, $who);
      $cnt = $data['users'][$who]['count'] ?? 0;
      $ids = $data['users'][$who]['invitees'] ?? [];
      $preview = implode(', ', array_slice(array_map('strval',$ids), 0, 20));
      tg('sendMessage', [
        'chat_id'=>$chat_id,
        'text'=>"User {$who} invited {$cnt} unique user(s)." . ($preview?"\nSample IDs: {$preview}":"")
      ]);
      return;
    }
    if($text === '/leaderboard'){
      // top 10 by count
      $rows = [];
      foreach($data['users'] as $id=>$u){ $rows[] = [$id, (int)($u['count'] ?? 0), $u['name'] ?? '']; }
      usort($rows, fn($a,$b)=> $b[1] <=> $a[1]);
      $top = array_slice($rows, 0, 10);
      $lines = [];
      $rank=1; foreach($top as $r){ $lines[] = "#{$rank} ".$r[2]." (".$r[0]."): ".$r[1]; $rank++; }
      $out = $lines? implode("\n", $lines) : 'No data yet.';
      tg('sendMessage', [ 'chat_id'=>$chat_id, 'text'=>$out ]);
      return;
    }
  }
}

function handle_callback($cb, &$data){
  $cid = $cb['id'];
  $from = $cb['from'];
  $uid = $from['id'];
  $msg = $cb['message'] ?? null;
  $data_id = $cb['data'] ?? '';

  ensure_user($data, $uid);

  if($data_id === 'try_again'){
    $ok = check_joined($uid);
    $data['users'][$uid]['joined_ok'] = $ok;
    save_data($data);

    if($ok){
      tg('answerCallbackQuery', ['callback_query_id'=>$cid, 'text'=>'✅ Verified — both channels joined!']);
      // If user has already invited 5 or more, show Admin contact
      if(($data['users'][$uid]['count'] ?? 0) >= 5){
        tg('sendMessage', [
          'chat_id' => $msg['chat']['id'],
          'text' => "Contact the Admin for premium",
          'reply_markup' => kb([[ ["text" => "Contact Admin", "url" => "https://t.me/rk_production_house"] ]])
        ]);
      } else {
        send_invite_prompt($msg['chat']['id']);
      }
    } else {
      tg('answerCallbackQuery', ['callback_query_id'=>$cid, 'text'=>'❌ Not verified yet. Please join both channels.']);
    }
    return;
  }

  if($data_id === 'invite'){
    $share_text = make_share_text($uid);
    $share_link = share_url($share_text);

    $text = "We are giving YouTube Premium to everyone for 1 month so come and grab the offer\n\nYour ID: ".$uid;

    // Show only the Forward button here (as requested)
    tg('sendMessage', [
      'chat_id'=>$msg['chat']['id'],
      'text'=>$text,
      'reply_markup'=>kb([
        [ ["text"=>"Forward", "url"=>$share_link] ]
      ]),
      'disable_web_page_preview'=>true
    ]);

    tg('answerCallbackQuery', ['callback_query_id'=>$cid, 'text'=>'Share the message to invite friends!']);
    return;
  }
}

// ======================
// MAIN: read webhook update
// ======================
$raw = file_get_contents('php://input');
if(!$raw){ echo 'OK'; exit; } // health checks
$update = json_decode($raw, true);

if(!$update){ log_error('Invalid JSON'); echo 'OK'; exit; }

$data = load_data();

try{
  if(isset($update['message'])){
    $msg = $update['message'];
    if(isset($msg['text'])){
      handle_commands($msg, $data);
    }
  } elseif(isset($update['callback_query'])){
    handle_callback($update['callback_query'], $data);
  }
} catch(Throwable $e){
  log_error('Exception: '.$e->getMessage());
}

echo 'OK';
