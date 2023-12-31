<?php

namespace App\Console\Commands;

use App\Http\Controllers\GameController;
use Illuminate\Console\Command;

use App\Models\User;
use App\Models\Action;
use App\Models\Room;

class RunBots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bots:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run Bots to play the Game';

    /**
     * Execute the console command.
     */

    private $__bots = [];
    private $Game = null;

    public function handle()
    {
        $this->__bots = $bots = User::where('type', 'bot')->get();
        $this->info('Registered Bots: '.number_format(count($this->__bots)));
        $rooms = Room::whereNull('passcode')->get();
        $this->info('Available Rooms: '.number_format(count($rooms)));
        if($rooms->isEmpty()) { // no games going, create bots
            return $this->__startNewRoom();
        }
        $this->Game = new GameController;
        $acted = false;
        foreach($rooms as $room) {
            $start = microtime(true);
            $acted = $this->__analyzeRoom($room) || $acted;
            $this->info('Analysis time: '.number_format(microtime(true) - $start, 3).' seconds');
        }
        if(!$acted && count($this->__bots) < MAX_BOTS) {
            $this->__startNewRoom();
        }
    }

    private function __analyzeRoom($room) {
        $this->Game->clearData();
        $start = microtime(true);
        $status = $room->analyze();
        $acted = false;
        $count = count($status['players']);
        $this->info('Room '.$room->id.': ['.implode(',', array_map(function($a) use ($status) {
            return $a.(!in_array($a, $status['playing']) ? 'x' : (($status['dealer'] == $a ? 'd' : '').($status['current'] == $a? 'c' : '')));
        }, array_keys($status['players']))).'] '.number_format(count($status['activities'])).' : '.number_format(microtime(true) - $start, 3).' seconds');
        if($count < MAX_ROOM_BOTS) {
            $this->__addBot($room);
            $acted = true;
        } 
        if(!empty($user_id = $status['current']) && ($bot = $this->__getBot($user_id)) && !empty($move = $bot->decideMove($status += ['hand' => $room->getHand($bot->id)]))) {
            $this->__analyzeMove($bot, $move, $status);
            // $status = $this->Game->checkEndRound([], $status);
        } else if(empty($user_id)) {
            $this->info('No current user');
            if(count($status['players']) > 1) {
                // restart game
                $this->Game->resetRoom($room);
                $this->info('Reset room');
            }
        } else if(empty($bot)) {
            // dump($user = User::find($user_id));
            $this->info('No bot selected');
            $user = User::find($user_id);
            if($user->type == 'disabled') {
                $this->__analyzeMove($user, ['action' => 'leave'], $status);
            }
        } else if(empty($move)) {
            dump($status);
            $this->info('Cannot decide move');
        }
        return $acted;
    }

    private function __analyzeMove($bot, $move, $status) {
        $room = Room::find($status['room_id']);
        switch($move['action']) {
            case 'pass':
                // Action::add($move['action'], $room->id, ['user_id' => $bot->id] + $move);
                $this->Game->passHand($bot->id, $status);
                $this->info('Bot '.$bot->id.' passed');
                return;
            case 'play':
                $bet = $move['bet'];
                $this->Game->playHand($bot, $bet, $status);
                $this->info('Bot '.$bot->id.' played and '.($bet > 0? 'won' : 'lost').' '.number_format(abs($bet)));
                $bet < 0 && $this->__kickBot($bot, $status);
                $this->Game->checkEndRound([], $status);
                return;
            case 'leave':
                // Action::add($move['action'], $room->id, ['user_id' => $bot->id] + $move);
                $this->Game->leaveRoom($bot);
                $this->info('Bot '.$bot->id.' left');
                return;
        }
        $this->info('Bot cannot take action');
        dump($move);
    }

    private function __kickBot($bot, $status) {
        if(count($status['players']) >= Room::find($status['room_id'])->max_players) {
            $this->info('Bot '.$bot->id.' is giving space');
            return $this->Game->leaveRoom($bot); // Action::add('leave', $room->id, ['user_id' => $bot->id]);
        } 
        if(BOT_DEFEATED > $bot->getPoints()) {
            $this->info('Bot '.$bot->id.' is defeated');
            $bot->type = 'disabled';
            $bot->save();
            return $this->Game->leaveRoom($bot); // Action::add('leave', $room->id, ['user_id' => $bot->id]);
        }
    }

    private function __addBot($room) {
        $bot = $this->__getBot();
        $this->Game->joinRoom($bot, $room);
        $this->info('Added Bot '.$bot->id);
    }

    private function __getBot($id = null) {
        if($id) {
            return $this->__bots->first(function($bot) use ($id) {
                return $bot->id == $id;
            });
        }
        if(empty($this->__bots)) {
            return $this->__createNewBot();
        }
        foreach($this->__bots as $bot) {
            if(empty($room_id = $bot->getRoomID(true))) {
                return $bot;
            }
        }
        return $this->__createNewBot();
    }

    private function __createNewBot() {
        $this->__bot []= $bot = User::create([
            'name'              => fake()->unique()->name, 
            'type'              => 'bot'
        ]);
        $bot->activateBot();
        return $bot;
    }

    private function __startNewRoom() {
        $bot = $this->__getBot();
        $room = Room::factory()->create([
            'user_id'   => $bot->id,
            'name'      => ucwords(fake()->unique()->word).' '.Room::count()
        ]);
        Action::add('join', $room->id, ['user_id' => $bot->id]);
        $this->info('Bot '.$bot->id.' started a room');
        return 0;
    }

}
