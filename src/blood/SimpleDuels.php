<?php

declare(strict_types=1);

namespace blood;

use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\item\Armor;
use pocketmine\item\ItemFactory;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\world\Position;

class SimpleDuels extends PluginBase implements Listener {
	
	private Config $config;
	private array $inQueue = [];
	private array $dueling = [];
	private bool $duelOngoing = false;
	
	public function onEnable() : void {
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->config = $this->getConfig();
		$this->getLogger()->info("§aSimpleDuels has been enabled!");
	}
	
	public function onDisable() : void {
		$this->getLogger()->info("§cSimpleDuels has been disabled!");
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		switch($command->getName()){
			case "duel":
				if(!$sender instanceof Player){
					$sender->sendMessage("Please run this command in-game");
					return false;
				}
				if(!in_array($sender->getName(), $this->dueling)){
					if(in_array($sender->getName(), $this->inQueue)){
						unset($this->inQueue[array_search($sender->getName(), $this->inQueue)]);
						$sender->sendMessage($this->config->get("left-queue"));
					}else{
						$this->cleanQueue();
						$this->inQueue[] = $sender->getName();
						$sender->sendMessage($this->config->get("joined-queue"));
						if(count($this->inQueue) > 1 && !$this->duelOngoing){
							var_dump($this->inQueue);
							$this->startDuel(current($this->inQueue), next($this->inQueue));
						}
					}
				}
			default:
				return false;
		}
	}
	
	public function cleanQueue(): void {
		foreach($this->inQueue as $queuedPlayer){
			if($this->getServer()->getPlayerByPrefix($queuedPlayer) === null){
				unset($this->inQueue[array_search($queuedPlayer, $this->inQueue)]);
			}
		}
	}
	
	public function prepareDuel(Player $player): void {
		$player->getInventory()->clearAll();
		$player->setMaxHealth(20);
		$player->setHealth(20);
		$player->getEffects()->clear();
		$player->getHungerManager()->setFood(20);
		$armor = [$this->config->get("helmet"), $this->config->get("chestplate"), $this->config->get("leggings"), $this->config->get("boots")];
		$armorType = 1;
		foreach($armor as $pieceData){
			$id = $pieceData["id"];
			$piece = ItemFactory::getInstance()->get($id);
			if(isset($pieceData["name"])){
				$name = $pieceData["name"];
				$piece->setCustomName($name);
			}
			if(isset($pieceData["enchantments"])){
				foreach($pieceData["enchantments"] as $name => $level){
					$enchantment = StringToEnchantmentParser::getInstance()->parse($name);
					if($enchantment !== null && $level > 0){
						$enchantment = new EnchantmentInstance($enchantment, $level);
						$piece->addEnchantment($enchantment);
					}
				}
			}
			if($piece instanceof Armor){
				if($armorType === 1){
					$player->getArmorInventory()->setHelmet($piece);
				}
				if($armorType === 2){
					$player->getArmorInventory()->setChestplate($piece);
				}
				if($armorType === 3){
					$player->getArmorInventory()->setLeggings($piece);
				}
				if($armorType === 4){
					$player->getArmorInventory()->setBoots($piece);
				}
			}
			$armorType += 1;
		}
		$items = $this->config->get("items");
		foreach($items as $id => $data){
			$item = ItemFactory::getInstance()->get($id);
			if(isset($data["name"])){
				$item->setCustomName($data["name"]);
			}
			if(isset($data["count"])){
				$item->setCount($data["count"]);
			}
			if(isset($data["enchantments"])){
				foreach($data["enchantments"] as $name => $level){
					$enchantment = StringToEnchantmentParser::getInstance()->parse($name);
					if($enchantment !== null && $level > 0){
						$enchantment = new EnchantmentInstance($enchantment, $level);
						$item->addEnchantment($enchantment);
					}
				}
			}
			$player->getInventory()->addItem($item);
		}
	}
	
	public function startDuel($p1, $p2): void {
		$player1 = $this->getServer()->getPlayerByPrefix($p1);
		$player2 = $this->getServer()->getPlayerByPrefix($p2);
		$this->prepareDuel($player1);
		$this->prepareDuel($player2);
		$pos1 = explode(":", $this->config->get("player1-position"));
		$pos2 = explode(":", $this->config->get("player2-position"));
		$player1->teleport(new Position((float)$pos1[0], (float)$pos1[1], (float)$pos1[2], $this->getServer()->getWorldManager()->getWorldByName($this->config->get("duel-world"))));
		$player2->teleport(new Position((float)$pos2[0], (float)$pos2[1], (float)$pos2[2], $this->getServer()->getWorldManager()->getWorldByName($this->config->get("duel-world"))));
		$player1->sendMessage(str_replace("{player}", $player2->getName(), $this->config->get("duel-against")));
		$player2->sendMessage(str_replace("{player}", $player1->getName(), $this->config->get("duel-against")));
		$this->duelOngoing = true;
		unset($this->inQueue[array_search($player1->getName(), $this->inQueue)]);
		unset($this->inQueue[array_search($player2->getName(), $this->inQueue)]);
		$this->dueling[] = $player1->getName();
		$this->dueling[] = $player2->getName();
    }
	
	public function onPlayerDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
		if($player->getWorld() === $this->getServer()->getWorldManager()->getWorldByName($this->config->get("duel-world"))){
			$cause = $player->getLastDamageCause();
			if($cause instanceof EntityDamageByEntityEvent) {
				$winner = $cause->getDamager();
				if($winner instanceof Player) {
					$this->endDuel($player->getName(), $winner->getName());
				}
			}
		}
	}
	
	public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
		if(in_array($player->getName(), $this->dueling)){
			foreach($this->dueling as $name){
				if($name != $player->getName()){
					$winner = $name;
				}
			}
			$this->endDuel($player->getName(), $winner);
		}
	}
	
	public function endDuel($loser, $winner): void {
		$this->duelOngoing = false;
		$this->dueling = [];
		$this->getServer()->broadcastMessage(str_replace(["{winner}", "{loser}"], [$winner, $loser], $this->config->get("broadcast-message")));
		$loser = $this->getServer()->getPlayerByPrefix($loser);
		if($loser !== null){
			$loser->sendMessage($this->config->get("duel-lost"));
		}
		$winner = $this->getServer()->getPlayerByPrefix($winner);
		$winner->sendMessage($this->config->get("duel-won"));
		$winner->teleport($this->getServer()->getWorldManager()->getDefaultWorld()->getSafeSpawn());
		$winner->getInventory()->clearAll();
		$winner->getArmorInventory()->clearAll();
		$winner->setMaxHealth(20);
		$winner->setHealth(20);
		$winner->getEffects()->clear();
		$winner->getHungerManager()->setFood(20);
	}
}
