<?php

declare(strict_types=1);

namespace VKU\Allocator\Domain\Enum;

enum RuntimePreset: string
{
    case PyTorchCuda = 'nvcr.io/nvidia/pytorch:24.10-py3 (PyTorch 2.5 + CUDA 12.6)';
    case TensorFlowCuda = 'nvcr.io/nvidia/tensorflow:24.10-tf2-py3 (TF 2.17 + CUDA 12.6)';
    case VllmInference = 'vllm/vllm-openai:v0.6.3 (vLLM High-Throughput Serving)';
    case UbuntuBuild = 'ubuntu:24.04-vkuhpc (GCC 14 / Rust / CMake / MPI Build Env)';
    case BareMetal = 'bare-metal://vku-pxe-checkout (Physical / Direct Serial Checkout)';

    public function shortLabel(): string
    {
        return match ($this) {
            self::PyTorchCuda => 'PyTorch 2.5 + CUDA 12.6',
            self::TensorFlowCuda => 'TensorFlow 2.17 + CUDA 12.6',
            self::VllmInference => 'vLLM OpenAI Serving Engine',
            self::UbuntuBuild => 'Ubuntu 24.04 C++/Rust/MPI Build',
            self::BareMetal => 'Bare Metal / Direct Hardware Checkout',
        };
    }
}
