<?php
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
$cid = $cb['i
