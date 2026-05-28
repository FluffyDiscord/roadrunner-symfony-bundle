<?php

if (
    !interface_exists(\Symfony\Component\DependencyInjection\ServicesResetterInterface::class)
    && interface_exists(\Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface::class)
) {
    class_alias(
        \Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface::class,
        \Symfony\Component\DependencyInjection\ServicesResetterInterface::class,
    );
}
