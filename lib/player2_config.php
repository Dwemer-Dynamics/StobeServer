<?php

const STOBE_PLAYER2_GAME_CLIENT_ID = '019cf504-1461-74e7-b4da-045b14e9019d';

function stobePlayer2GameKeyHeader(): string
{
    return 'player2-game-key: ' . STOBE_PLAYER2_GAME_CLIENT_ID;
}
