@echo off
setlocal

set "shard_total=%~1"
if "%shard_total%"=="" set "shard_total=4"

pushd "%~dp0\..\..\.." || exit /b 1
docker compose -f compose.yml -f compose.development.yml up -d hakoniwa-dev
if errorlevel 1 goto failed
docker compose -f compose.yml -f compose.development.yml exec -T hakoniwa-dev bash tests/scripts/run_parallel_tests.sh "%shard_total%"
set "test_exit_code=%errorlevel%"
popd

exit /b %test_exit_code%

:failed
set "test_exit_code=%errorlevel%"
popd
exit /b %test_exit_code%
