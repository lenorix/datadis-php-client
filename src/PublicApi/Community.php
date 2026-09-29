<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\PublicApi;

/** Autonomous communities and cities, with the codes the public API uses. */
enum Community: string
{
    case Andalucia = '01';
    case Aragon = '02';
    case Asturias = '03';
    case Baleares = '04';
    case Canarias = '05';
    case Cantabria = '06';
    case CastillaYLeon = '07';
    case CastillaLaMancha = '08';
    case Cataluna = '09';
    case ComunidadValenciana = '10';
    case Extremadura = '11';
    case Galicia = '12';
    case Madrid = '13';
    case Murcia = '14';
    case Navarra = '15';
    case PaisVasco = '16';
    case LaRioja = '17';
    case Ceuta = '18';
    case Melilla = '19';
}
