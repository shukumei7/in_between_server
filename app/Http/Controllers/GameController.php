<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Room;
use App\Models\Action;

class GameController extends Controller
{

    private $__user = null;
    private $__room = null;
    private $__status = [];

    public function status() {
        $this->__clearData();
        if(false === $room = $this->__getUserRoom()) {
            return response()->json(['message' => 'User not logged in'], 401);
        }
        if(is_numeric($room) && $room == 0) {
            return $this->__listRooms();
        }
        return $this->__getRoomStatus();
    }

    public function play(Request $request) {
        $this->__clearData();
        if(!empty($request->room_id)) {
            return $this->__joinSpecificRoom($request);
        }
        if(true !== $return = $this->__checkUserRoom()) {
            return $return;
        }
        $user = $this->__user;
        $room = $this->__room;
        $this->__status = $status = $room->analyze();
        if(count($status['players']) < 2) {
            return response()->json(['message' => 'Waiting for more players'] + $status + $this->__getUserStatus(), 302);
        }
        if($status['current'] != $user->id) {
            return response()->json(['message' => 'It is not your turn'] + $status + $this->__getUserStatus(), 200);
        }
        if(empty($request->action)) {
            return response()->json(['message' => 'An action is required'] + $status + $this->__getUserStatus(), 302);
        }
        switch($request->action) {
            case 'play':
                return $this->__playHand($request);
            case 'pass':
                return $this->__passHand();
        }
        return response()->json(['message' => 'You made an invalid action'] + $status + $this->__getUserStatus(), 302);
    }

    private function __clearData() {
        $this->__room = $this->__user = $this->__status = null;
    }

    private function __listRooms() {
        $rooms = Room::select('name')->orderBy('created_at', 'asc')->get()->toArray();
        return response()->json(['message' => 'Listing all rooms', 'rooms' => $rooms], 200);
    }

    private function __checkUserRoom() {
        if($this->__room) {
            return true;
        }
        if(false === $room = $this->__getUserRoom()) {
            return response()->json(['message' => 'User not logged in'], 302);
        }
        if(is_numeric($room) && $room == 0) {
            return $this->__joinRandomRoom();
        }
        return true;
    }

    private function __getUserRoom() {
        if(empty($user = Auth::user())) {
            return false;
        }
        $this->__user = $user;
        if(0 == $room_id = $user->getRoomID()) {
            return 0;
        }
        return $this->__room = $room = Room::find($room_id);
    }

    private function __joinRandomRoom() {
        $rooms = Room::where('passcode', '=', '')->orWhereNull('passcode')->orderBy('created_at', 'asc')->get();
        if($rooms->isEmpty()) {
            return $this->__createRoom();
        }
        foreach($rooms as $room) {
            if($return = $this->__joinRoom($room)) {
                return $return;
            }
        }
        return $this->__createRoom();
    }

    private function __joinSpecificRoom($request) {
        if(empty($user = Auth::user())) {
            return response()->json(['message' => 'User not logged in'], 401);
        }
        if($room_id = $user->getRoomID()) {
            return response()->json(['message' => 'You are already in a room'], 302);
        }
        return $this->__joinRoom(Room::find($room_id), !empty($request->passcode) ? $request->passcode : null);
    }

    private function __joinRoom($room, $passcode = null) {
        if(empty($room) || empty($room->id)) {
            return response()->json(['message' => 'Invalid room'], 302);
        }
        $status = $room->analyze();
        if($room->isFull()) {
            dd('Full: '.number_format($room->max_players).' : '.count($status['players']));
            return false;
        }
        if($room->isLocked() && $passcode != $room->passcode) {
            dd('Locked: '.$room->passcode);
            return false;
        }
        Action::add('join', $room->id, ['user_id' => $this->__user->id]);
        $this->__room = $room;
        session([$this->__getPointUpdateSessionKey() => 0]); // reset session for new rooms and force getting points
        if(count($status['players']) == 1) {
            return $this->__startGame();
        }
        return $this->__getRoomStatus('Joined a room');
    }

    private function __startGame() {
        $room = $this->__room;
        $status = $room->analyze(true);
        $this->__getPots($status['players']);
        Action::add('shuffle', $room->id);
        return $this->__startRound();
    }

    private function __getPots($players) {
        $room = $this->__room;
        foreach($players as $user_id) {
            Action::add('pot', $room->id, ['user_id' => $user_id, 'bet' => -1* $room->pot]);
        }
        // var_dump('Old time: '.$room->updated_at);
        sleep(1);
        $room->updated_at = now(); // force update of points
        $room->save();
        $this->__room = $room;
        // var_dump('New time: '.$this->__room->updated_at);
    }

    private function __startRound($message  = null) {
        $room = $this->__room;
        $status = $room->analyze(true);
        // var_dump($status['dealer']);
        $count = count($players = $status['playing']);
        $t = array_search($status['dealer'], $players) + 1;
        // var_dump('First Draw: '.$t.' vs '.$count);
        for($x = 0; $x < $count * 2; $x++) {
            if($t >= $count) $t = 0;
            Action::add('deal', $room->id, ['user_id' => $players[$t++], 'card' => $room->dealCard()]);
        }
        return $this->__getRoomStatus(($message ? $message.'. ' : '').'New round started!');
    }

    private function __getRoomStatus($refresh = false) {
        if($this->__status && !$refresh) {
            return response()->json($this->__status + $this->__getUserStatus(), 200);
        }
        if(true !== $return = $this->__checkUserRoom()) {
            return $return;
        }
        $room = $this->__room;
        if(empty($status = $room->analyze($refresh))) {
            return response()->json(['message' => 'Failed to get room status'], 302);
        }
        $user = $this->__user;
        if(is_string($refresh)) {
            $message = $refresh;
        } else if(count($status['players']) <= 1) {
            $message = 'Waiting for more players';
        } else if($status['current'] != $user->id) {
            $message = 'Waiting for '.User::find($status['current'])->name;
        } else if($status['current'] == $user->id) {
            $message = 'It is your turn!';
        }
        return response()->json(compact('message') + $status + $this->__getUserStatus(), 200);
    }

    private function __getUserStatus() {
        $user = $this->__user;
        $room = $this->__room;
        $out = ['hand' => $room->getHand($user->id)];
        $k = $this->__getPointUpdateSessionKey();
        $update = empty(session($k)) || session($k) < $room->updated_at;
        // var_dump('Session '.$k.' = '.session($k).' vs '.$room->updated_at.' = '.$update);
        if($update) {
            $out['points'] = $user->getPoints();
            session([$k => $room->updated_at]);
        }
        return $out;
    }

    private function __getPointUpdateSessionKey() {
        return SESSION_POINT_UPDATE.'-'.$this->__user->id;
    }

    private function __createRoom() {
        $user = $this->__user ?: Auth::user();
        $this->__room = $room = Room::factory()->create(['user_id' => $user->id]);
        Action::add('join', $room->id, ['user_id' => $user->id]);
        return $this->__getRoomStatus('Room created');
    }

    private function __playHand($request) {
        if(true != $return = $this->__checkUserRoom()) {
            return $return;
        }
        $room = $this->__room;
        if($room->pot > 0 && (empty($request->bet) || !is_numeric($request->bet) || $request->bet < 1)) {
            return response()->json(['message' => 'You need to place a bet of at least 1'], 302);
        }
        $bet = $request->bet;
        $status = $this->__status ?: $room->analyze();
        if($room->pot > 0 && $bet > $status['pot']) {
            return response()->json(['message' => 'You cannot bet more than the pot of '.number_format($status['pot'])] + $status, 302);
        }
        $points = 0;
        $user = $this->__user;
        if($room->pot > 0 && ($points = $user->getPoints()) > 0 && $bet > $points) {
            return response()->json(['message' => 'You cannot bet more than your points of '.number_format($points), 'points' => $points], 302);
        }
        if($room->pot > 0 && $points < 1 && $bet > RESTRICT_BET) {
            return response()->json(['message' => 'You can only bet a max of '.number_format(RESTRICT_BET).' if your points are less than 1', 'points' => $points], 302);
        }
        $hand = $room->getHand($user_id = $user->id);
        $card = $room->dealCard();
        if(intval($card) < intval(min($hand)) || intval($card) > intval(max($hand))) {
            $bet *= -1; // lose
        }
        Action::add('play', $room->id, compact('user_id', 'bet', 'card'));
        if($status['pot'] == $bet) { // winning means clearing pot
            $this->__getPots($status['playing']); // make playing pay
        }
        $hand []= $card;
        $output = ['message' => 'You '.($bet > 0? 'win' : 'lose').' '.number_format($b = abs($bet)).' point'.($b == 1? '' : 's')] + ['cards' => $hand, 'points' => $user->getPoints(true)];
        return $this->__checkEndRound($output, $status, true);
    }

    private function __passHand() {
        if(true != $return = $this->__checkUserRoom()) {
            return $return;
        }
        Action::add('pass', $this->__room->id, [ 'user_id' => $this->__user->id]);
        $output = ['message' => 'You passed'];
        return $this->__checkEndRound($output, $room->analyze());
    }

    private function __checkEndRound($output, $status, $refresh = false) {
        if($status['dealer'] != $status['current']) { // not end round
            return response()->json($output + ($refresh ? $this->__room->analyze(true) : $status) + $this->__getUserStatus(), 200);
        }
        // end of round
        // check if new players came in
        if($new_players = array_diff($status['players'], $status['playing'])) {
            $this->__getPots($new_players);
        }
        // check if deck has enough cards for all players
        if($status['deck'] < count($status['players']) * 3) {
            Action::add('shuffle', $status['room_id']);
        }
        Action::add('rotate', $status['room_id']);
        if(isset($output['points'])) {
            session([$this->__getPointUpdateSessionKey() => 0]);
        }
        return $this->__startRound($output['message']);
    }
}