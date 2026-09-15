@echo off
setlocal

set "script_directory=%~dp0"
set "shard_total=%~1"
if "%shard_total%"=="" set "shard_total=4"
set "scope=%~2"
if "%scope%"=="" set "scope=full"
set "runner_arguments="
:collect_arguments
if "%~3"=="" goto arguments_collected
set "runner_arguments=%runner_arguments% %3"
shift
goto collect_arguments
:arguments_collected

pushd "%script_directory%\..\..\.." || exit /b 1
docker compose -f compose.yml -f compose.development.yml up -d hakoniwa-dev
if errorlevel 1 goto failed
docker compose -f compose.yml -f compose.development.yml exec -T hakoniwa-dev bash tests/scripts/run_parallel_tests.sh "%shard_total%" "%scope%" %runner_arguments%
set "test_exit_code=%errorlevel%"
popd

exit /b %test_exit_code%

:failed
set "test_exit_code=%errorlevel%"
popd
exit /b %test_exit_code%
