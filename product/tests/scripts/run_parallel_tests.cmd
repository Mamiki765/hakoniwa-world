@echo off
setlocal

set "shard_total=%~1"
if "%shard_total%"=="" set "shard_total=4"

pushd "%~dp0\..\..\.." || exit /b 1
docker compose build hakoniwa-web
if errorlevel 1 goto failed
docker compose up -d --no-deps hakoniwa-web
if errorlevel 1 goto failed
docker compose exec -T hakoniwa-web bash tests/scripts/run_parallel_tests.sh "%shard_total%"
set "test_exit_code=%errorlevel%"
popd

exit /b %test_exit_code%

:failed
set "test_exit_code=%errorlevel%"
popd
exit /b %test_exit_code%
