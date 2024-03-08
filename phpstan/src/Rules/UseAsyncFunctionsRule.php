<?php


namespace Nadybot\phpstan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Rules\Rule;
use PHPStan\ShouldNotHappenException;
use TheCodingMachine\Safe\PHPStan\Utils\FunctionListLoader;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * This rule checks that no "sync" filesystem functions are used in code.
 *
 * @implements Rule<Node\Expr\FuncCall>
 */
class UseAsyncFunctionsRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr\FuncCall::class;
    }

    /**
     * @param Node\Expr\FuncCall $node
     * @param \PHPStan\Analyser\Scope $scope
     * @return string[]
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Name) {
            return [];
        }
        $functionName = $node->name->toString();
        $syncFunctions = [
          "fclose" => "\\Amp\\File::close()",
          "fwrite" => "\\Amp\\File::write()",
          "fputs" => "\\Amp\\File::write()",
          "fgets" => "\\Amp\\File::read()",
          "fgetc" => "\\Amp\\File::read()",
          "fread" => "\\Amp\\File::read()",
          "fseek" => "\\Amp\\File::seek()",
          "feof" => "\\Amp\\File::eof()",
          "ftell" => "\\Amp\\File::tell()",
          "fopen" => "\\Amp\\File\\openFile()",
          "file" => "\\Amp\\ByteStream\\splitLines(\\Amp\\File\\openFile())",
          "stat" => "\\Amp\\File\\getStatus()",
          "lstat" => "\\Amp\\File\\getLinkStatus()",
          "symlink" => "\\Amp\\File\\createSymlink()",
          "link" => "\\Amp\\File\\createHardlink()",
          "readlink" => "\\Amp\\File\\resolveSymlink()",
          "rename" => "\\Amp\\File\\move()",
          "unlink" => "\\Amp\\File\\deleteFile()",
          "mkdir" => "\\Amp\\File\\createDirectory()",
          "rmdir" => "\\Amp\\File\\deleteDirectory()",
          "scandir" => "\\Amp\\File\\listFiles()",
          "chmod" => "\\Amp\\File\\changePermissions()",
          "chown" => "\\Amp\\File\\changeOwner()",
          "chgrp" => "\\Amp\\File\\changeOwner()",
          "touch" => "\\Amp\\File\\touch()",
          "file_get_contents" => "\\Amp\\File\\read()",
          "file_put_contents" => "\\Amp\\File\\write()",
          "file_exists" => "\\Amp\\File\\exists()",
          "fileatime" => "\\Amp\\File\\getAccessTime()",
          "filemtime" => "\\Amp\\File\\getModificationTime()",
          "filectime" => "\\Amp\\File\\getCreationTime()",
          "is_link" => "\\Amp\\File\\isSymlink()",
          "is_file" => "\\Amp\\File\\isFile()",
          "is_dir" => "\\Amp\\File\\isDirectory()",
          "filesize" => "\\Amp\\File\\getSize()",
        ];


        $replacement = $syncFunctions[$functionName];
        if (isset($replacement)) {
            return ["Function {$functionName} has an async counterpart in {$replacement}."];
        }

        return [];
    }
}

