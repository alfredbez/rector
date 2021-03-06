<?php

declare(strict_types=1);

namespace Rector\NodeTypeResolver\TypeMapper;

use PhpParser\Node;
use PHPStan\Type\Type;
use Rector\Exception\NotImplementedException;
use Rector\NodeTypeResolver\Contract\PhpParser\PhpParserNodeMapperInterface;

final class PhpParserNodeMapper
{
    /**
     * @var PhpParserNodeMapperInterface[]
     */
    private $phpParserNodeMappers = [];

    /**
     * @param PhpParserNodeMapperInterface[] $phpParserNodeMappers
     */
    public function __construct(array $phpParserNodeMappers)
    {
        $this->phpParserNodeMappers = $phpParserNodeMappers;
    }

    public function mapToPHPStanType(Node $node): Type
    {
        foreach ($this->phpParserNodeMappers as $phpParserNodeMapper) {
            if (! is_a($node, $phpParserNodeMapper->getNodeType())) {
                continue;
            }

            return $phpParserNodeMapper->mapToPHPStan($node);
        }

        throw new NotImplementedException();
    }
}
