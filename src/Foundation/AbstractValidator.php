<?php

namespace Discuz\Foundation;

use Discuz\Api\ExceptionHandler\ValidationExceptionHandler;
use Illuminate\Support\Arr;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;

abstract class AbstractValidator
{

    protected $validator;

    protected $rules = [];

    public function __construct(Factory $validator)
    {
        $this->validator = $validator;
    }


    /**
     * @param array $attributes
     * @throws ValidationException
     */
    public function valid(array $attributes) {
        $validator = $this->make($attributes);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    public function make(array $attributes)
    {
        $rules = Arr::only($this->getRules(), array_keys($attributes));

        $validator = $this->validator->make($attributes, $rules, $this->getMessages());

        return $validator;
    }

    abstract protected function getRules();

    abstract protected function getMessages();

}