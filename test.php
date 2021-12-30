<?php

class A {
  public int $i;
}
class B extends A {
  public int $j;
}

$a = new A();
$a->i = 1;
$a->j = 2;

var_dump(get_class_vars(A::class));
