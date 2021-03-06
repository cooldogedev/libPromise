<?php

/**
 *  Copyright (c) 2021 cooldogedev
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *  SOFTWARE.
 */

declare(strict_types=1);

namespace cooldogedev\libPromise\thread;

use Closure;
use cooldogedev\libPromise\constant\PromiseState;
use cooldogedev\libPromise\error\ThreadedPromiseError;
use cooldogedev\libPromise\IPromise;
use cooldogedev\libPromise\Promise;
use cooldogedev\libPromise\traits\SharedPromisePartsTrait;
use Threaded;
use Throwable;

final class ThreadedPromise extends Threaded implements IPromise
{
    use SharedPromisePartsTrait;

    protected ?Closure $onSettlement;

    protected ThreadedPromiseError $error;

    protected bool $settled;

    protected int $state;

    protected bool $hasThenables;
    protected Threaded $thenables;

    protected bool $hasCatchers;
    protected Threaded $catchers;

    protected mixed $response;
    protected bool $responseSerialized;

    public function __construct(protected ?Closure $executor = null, protected ?Closure $onCompletion = null)
    {
        $this->onSettlement = null;

        $this->error = new ThreadedPromiseError();

        $this->settled = false;

        $this->state = PromiseState::PROMISE_STATE_PENDING;

        $this->thenables = new Threaded();
        $this->hasThenables = false;

        $this->catchers = new Threaded();
        $this->hasCatchers = false;

        $this->response = null;
        $this->responseSerialized = false;
    }

    public function handleRejection(): void
    {
        if ($this->hasCatchers()) {
            foreach ($this->getCatchers() as $catcher) {
                $catcher($this->getError());
            }
        }

        $this->setState(PromiseState::PROMISE_STATE_REJECTED);
    }

    public function hasCatchers(): bool
    {
        return $this->hasCatchers;
    }

    public function getCatchers(): Threaded
    {
        return $this->catchers;
    }

    public function getError(): ThreadedPromiseError
    {
        return $this->error;
    }

    public function setError(?Throwable $error): void
    {
        $this->getError()->updateParameters($error);
    }

    public function isResponseSerialized(): bool
    {
        return $this->responseSerialized;
    }

    public function then(Closure $resolve): IPromise
    {
        if (!$this->hasThenables()) {
            $this->setHasThenables(true);
        }
        $this->thenables[] = $resolve;
        return $this;
    }

    public function hasThenables(): bool
    {
        return $this->hasThenables;
    }

    public function setHasThenables(bool $hasThenables): void
    {
        $this->hasThenables = $hasThenables;
    }

    public function catch(Closure $closure): IPromise
    {
        if (!$this->hasCatchers()) {
            $this->setHasCatchers(true);
        }
        $this->catchers[] = $closure;
        return $this;
    }

    public function setHasCatchers(bool $hasCatchers): void
    {
        $this->hasCatchers = $hasCatchers;
    }

    public function handleResolve(): void
    {
        if ($this->hasThenables()) {
            foreach ($this->getThenables() as $thenable) {
                $response = $thenable($this->getResponse());
                $response && $this->setResponse($response);
            }
        }

        $this->setState(PromiseState::PROMISE_STATE_FULFILLED);
    }

    public function getThenables(): Threaded
    {
        return $this->thenables;
    }

    public function getResponse(): mixed
    {
        return $this->responseSerialized ? unserialize($this->response) : $this->response;
    }

    public function setResponse(mixed $response): void
    {
        $serialize = is_array($response);
        $this->response = $serialize ? serialize($response) : $response;
        $this->responseSerialized = $serialize;
    }

    public function onCompletion(?Closure $onCompletion): IPromise
    {
        $this->onCompletion = $onCompletion;
        return $this;
    }

    public function asPromise(): Promise
    {
        $promise = new Promise($this->getExecutor());

        $promise->setResponse($this->getResponse());

        $promise->setError($this->getError()->asException());

        $promise->finally($this->getOnSettlement());

        $promise->setState($this->getState());

        $promise->setSettled($this->isSettled());

        foreach ($this->getCatchers() as $catcher) {
            $promise->catch($catcher);
        }

        foreach ($this->getThenables() as $thenable) {
            $promise->then($thenable);
        }

        return $promise;
    }

    public function reject(?Throwable $throwable): IPromise
    {
        $this->getError()->updateParameters($throwable);
        return $this;
    }

    /**
     * This method is ran on the main thread after settlement, it's similar to @link IPromise::finally()
     * except this one gets the response as a parameter.
     */
    public function getOnCompletion(): ?Closure
    {
        return $this->onCompletion;
    }
}
