<?php

/*
 * libasynql_v3
 *
 * Copyright (C) 2018 SOFe
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace poggit\libasynql\base;

use InvalidArgumentException;
use poggit\libasynql\SqlThread;
use Threaded;
use function count;
use function is_array;

class SqlThreadPool implements SqlThread{
	private $workerCreator;
	/** @var BaseSqlThread[] */
	private $workers = [];
	/** @var int */
	private $workerLimit;

	/** @var Threaded */
	private $bufferSend;
	/** @var Threaded */
	private $bufferRecv;

	/**
	 * SqlThreadPool constructor.
	 *
	 * @param callable $workerCreator create a child worker: <code>function(?Threaded $bufferSend = null, ?Threaded $bufferRecv = null) : {@link BaseSqlThread}{}</code>
	 * @param int      $workerLimit   the maximum number of workers to create. Workers are created lazily.
	 */
	public function __construct(callable $workerCreator, int $workerLimit){
		$this->workerCreator = $workerCreator;
		$this->workerLimit = $workerLimit;
		$this->bufferSend = new Threaded();
		$this->bufferRecv = new Threaded();
		if(empty($this->workers)){
			$this->addWorker();
		}
	}

	private function addWorker() : void{
		$this->workers[] = ($this->workerCreator)($this->bufferSend, $this->bufferRecv);
	}

	public function join() : void{
		foreach($this->workers as $worker){
			$worker->join();
		}
	}

	public function stopRunning() : void{
		foreach($this->workers as $worker){
			$worker->stopRunning();
		}
	}

	public function addQuery(int $queryId, int $mode, string $query, array $params) : void{
		$this->bufferSend[] = [$queryId, $mode, $query, $params];
		foreach($this->workers as $worker){
			if($worker->isWorking()){
				return;
			}
		}

		if(count($this->workers) < $this->workerLimit){
			$this->addWorker();
		}
	}

	public function readResults(array &$callbacks) : void{
		while(is_array($resultSet = $this->bufferRecv->shift())){
			[$queryId, $result] = $resultSet;
			if(!isset($callbacks[$queryId])){
				throw new InvalidArgumentException("Missing handler for query #$queryId");
			}

			$callbacks[$queryId]($result);
			unset($callbacks[$queryId]);
		}
	}

	public function connCreated() : bool{
		return $this->workers[0]->connCreated();
	}

	public function hasConnError() : bool{
		return $this->workers[0]->hasConnError();
	}

	public function getConnError() : ?string{
		return $this->workers[0]->getConnError();
	}

	public function getLoad() : float{
		return $this->bufferSend->count() / (float) $this->workerLimit;
	}
}